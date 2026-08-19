/**
 * AFOP Frontend Interactivity Script
 * Handles live validation, incomplete lead capture, repeat order prompts, and WhatsApp support modals.
 */

(function ($) {
    'use strict';

    if (typeof afop_data === 'undefined') {
        return;
    }

    var AFOP = {
        sessionKey: 'afop_' + Math.random().toString(36).substring(2, 15),
        repeatConfirmed: false,
        leadCaptured: false,
        typingTimer: null,
        debounceInterval: 600,

        init: function () {
            this.bindEvents();
            this.checkClientBlockedOnLoad();
        },

        bindEvents: function () {
            var self = this;

            // Close Modal Events
            $(document).on('click', '#afop-modal-close, #afop-btn-modal-dismiss, #afop-modal-overlay', function (e) {
                if (e.target === this || $(this).attr('id') === 'afop-modal-close' || $(this).attr('id') === 'afop-btn-modal-dismiss') {
                    self.hideModal();
                }
            });

            // Live Incomplete Order & Phone Watcher
            $(document).on('input keyup change', '#billing_phone, input[name*="phone"]', function () {
                self.handlePhoneTyping($(this).val());
            });

            // Checkout Form Submission Interception
            $('form.checkout').on('checkout_place_order', function () {
                return self.handleCheckoutSubmission();
            });

            // Custom place order button clicks (CartFlows / Gutenberg / 1-step checkouts)
            $(document).on('click', '#place_order, .wc-block-checkout__actions_row button', function (e) {
                var phoneVal = self.getPhoneValue();
                if (phoneVal && !self.repeatConfirmed) {
                    var validation = self.validateBDPhone(phoneVal);
                    if (!validation.valid) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        self.showInvalidPhoneModal(validation.reason);
                        return false;
                    }
                }
            });
        },

        getPhoneValue: function () {
            var $phone = $('#billing_phone');
            if (!$phone.length) {
                $phone = $('input[name*="phone"]:visible').first();
            }
            return $phone.length ? $.trim($phone.val()) : '';
        },

        normalizePhone: function (phone) {
            if (!phone) return '';
            // Convert bangla numerals
            var bn = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
            var en = ['0','1','2','3','4','5','6','7','8','9'];
            for (var i = 0; i < bn.length; i++) {
                phone = phone.replace(new RegExp(bn[i], 'g'), en[i]);
            }
            phone = phone.replace(/[^\d+]/g, '');
            if (phone.indexOf('+88') === 0) phone = phone.substring(3);
            else if (phone.indexOf('88') === 0 && phone.length > 11) phone = phone.substring(2);
            phone = phone.replace(/^0+/, '');
            return '0' + phone;
        },

        validateBDPhone: function (phone) {
            var norm = this.normalizePhone(phone);
            if (norm.length !== 11) {
                return { valid: false, reason: 'নম্বরটি অবশ্যই ১১ ডিজিটের হতে হবে।' };
            }
            if (!/^01[3-9]\d{8}$/.test(norm)) {
                return { valid: false, reason: 'সঠিক অপারেটর নম্বর লিখুন (013-019)।' };
            }
            // Obvious dummy patterns
            var dummyPatterns = [
                '01234567890', '01987654321', '01712345678', '01812345678', '01912345678',
                '01700000000', '01800000000', '01900000000', '01600000000', '01500000000',
                '01711111111', '01811111111', '01911111111', '01799999999'
            ];
            if (dummyPatterns.indexOf(norm) !== -1) {
                return { valid: false, reason: 'টেস্ট বা ডামি নম্বর গ্রহণযোগ্য নয়।' };
            }
            var suffix = norm.substring(3);
            if (/^(\d)\1{7}$/.test(suffix)) {
                return { valid: false, reason: 'সব সংখ্যা একই এমন ডামি নম্বর দেওয়া যাবে না।' };
            }
            return { valid: true, normalized: norm };
        },

        handlePhoneTyping: function (rawPhone) {
            var self = this;
            clearTimeout(this.typingTimer);

            this.typingTimer = setTimeout(function () {
                var norm = self.normalizePhone(rawPhone);
                // Condition: ONLY trigger incomplete capture when phone is full 11 digits & valid!
                if (norm.length === 11) {
                    var validation = self.validateBDPhone(norm);
                    if (validation.valid && afop_data.enable_incomplete === 'yes') {
                        self.captureIncompleteOrder(norm);
                    }
                }
            }, self.debounceInterval);
        },

        captureIncompleteOrder: function (phone) {
            var name = $.trim($('#billing_first_name').val() + ' ' + ($('#billing_last_name').val() || ''));
            var email = $('#billing_email').val() || '';
            var address = $('#billing_address_1').val() || '';
            var city = $('#billing_city').val() || '';

            $.ajax({
                url: afop_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'afop_capture_incomplete_order',
                    nonce: afop_data.nonce,
                    phone: phone,
                    name: name,
                    email: email,
                    address: address,
                    city: city,
                    session_key: this.sessionKey
                },
                success: function (res) {
                    // Captured silently
                }
            });
        },

        checkClientBlockedOnLoad: function () {
            var self = this;
            $.ajax({
                url: afop_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'afop_check_blocked_ajax',
                    nonce: afop_data.nonce
                },
                success: function (res) {
                    if (res.success && res.data && res.data.is_blocked) {
                        self.showBlockedModal(res.data.title, res.data.message);
                    }
                }
            });
        },

        handleCheckoutSubmission: function () {
            var self = this;
            var phone = this.getPhoneValue();

            // 1. Check Phone validity
            if (afop_data.enable_bd_validation === 'yes' && phone) {
                var validation = this.validateBDPhone(phone);
                if (!validation.valid) {
                    this.showInvalidPhoneModal(validation.reason);
                    return false;
                }
            }

            // 2. Check Repeat Order if not already confirmed
            if (afop_data.enable_repeat_check === 'yes' && !this.repeatConfirmed && phone) {
                var shouldHalt = true;
                var repeatCheckSync = false;

                // Sync AJAX repeat check before proceeding
                $.ajax({
                    url: afop_data.ajax_url,
                    type: 'POST',
                    async: false,
                    data: {
                        action: 'afop_check_repeat_order_ajax',
                        nonce: afop_data.nonce,
                        phone: phone
                    },
                    success: function (res) {
                        if (res.success && res.data && res.data.is_repeat) {
                            self.showRepeatOrderModal(res.data);
                            repeatCheckSync = true;
                        }
                    }
                });

                if (repeatCheckSync) {
                    return false;
                }
            }

            return true;
        },

        showModal: function (options) {
            var $overlay = $('#afop-modal-overlay');
            var $iconWrap = $('#afop-modal-icon');
            var $title = $('#afop-modal-title');
            var $desc = $('#afop-modal-desc');
            var $extra = $('#afop-modal-extra');
            var $actions = $('#afop-modal-actions');

            $iconWrap.removeClass('afop-icon-warning afop-icon-danger afop-icon-info').addClass(options.iconClass || 'afop-icon-warning');
            $iconWrap.html(options.icon || '⚠️');
            $title.text(options.title || '');
            $desc.text(options.message || '');
            $extra.html(options.extra || '');
            $actions.html(options.actions || '');

            $overlay.addClass('afop-show');
        },

        hideModal: function () {
            $('#afop-modal-overlay').removeClass('afop-show');
        },

        showInvalidPhoneModal: function (reason) {
            var self = this;
            var title = afop_data.i18n.invalid_phone_title || 'ভুল মোবাইল নম্বর!';
            var msg = afop_data.i18n.invalid_phone_msg || 'আপনার নম্বরটি ভুল। দয়া করে সঠিক ১১ ডিজিটের বাংলাদেশী মোবাইল নম্বর লিখুন।';

            var actionsHtml = '<button type="button" class="afop-btn afop-btn-primary" id="afop-btn-modal-dismiss"><i class="fa-solid fa-check"></i> ' + (afop_data.i18n.ok_btn || 'ঠিক আছে') + '</button>';

            this.showModal({
                iconClass: 'afop-icon-danger',
                icon: '<i class="fa-solid fa-mobile-screen"></i>',
                title: title,
                message: msg,
                actions: actionsHtml
            });

            // Focus on phone input after modal close
            $(document).one('click', '#afop-btn-modal-dismiss, #afop-modal-close', function () {
                $('#billing_phone').focus();
            });
        },

        showBlockedModal: function (title, msg) {
            title = title || afop_data.i18n.blocked_title || 'অর্ডার ব্লক করা হয়েছে!';
            msg = msg || afop_data.i18n.blocked_msg || 'দুঃখিত, আপনার আইপি বা মোবাইল নম্বরটি নিরাপত্তা কারণে ব্লক করা আছে।';

            var waLink = afop_data.whatsapp_link || '#';
            var waText = afop_data.i18n.whatsapp_btn || 'হোয়াটসঅ্যাপে যোগাযোগ করুন';

            var actionsHtml = '';
            if (waLink && waLink !== '#') {
                actionsHtml += '<a href="' + waLink + '" target="_blank" class="afop-btn afop-btn-whatsapp"><i class="fa-brands fa-whatsapp"></i> ' + waText + '</a>';
            }
            actionsHtml += '<button type="button" class="afop-btn afop-btn-cancel" id="afop-btn-modal-dismiss"><i class="fa-solid fa-xmark"></i> বন্ধ করুন</button>';

            this.showModal({
                iconClass: 'afop-icon-danger',
                icon: '<i class="fa-solid fa-ban"></i>',
                title: title,
                message: msg,
                actions: actionsHtml
            });
        },

        showRepeatOrderModal: function (data) {
            var self = this;
            var title = data.title || afop_data.i18n.repeat_order_title || 'পুনরায় অর্ডার নিশ্চিতকরণ';
            var msg = data.message || 'আপনি কিছুক্ষণ আগে এই প্রোডাক্টটি অর্ডার করেছিলেন। আপনি কি আবার এই একই প্রোডাক্ট অর্ডার করতে চান?';
            var confirmText = data.confirm_btn || afop_data.i18n.confirm_btn || 'হ্যাঁ, আবার অর্ডার করুন';
            var cancelText = data.cancel_btn || afop_data.i18n.cancel_btn || 'না, বাতিল করুন';

            var actionsHtml = '<div class="afop-repeat-actions">' +
                '<button type="button" class="afop-btn afop-btn-primary" id="afop-btn-repeat-confirm"><i class="fa-solid fa-circle-check"></i> ' + confirmText + '</button>' +
                '<button type="button" class="afop-btn afop-btn-cancel" id="afop-btn-repeat-cancel"><i class="fa-solid fa-circle-xmark"></i> ' + cancelText + '</button>' +
                '</div>';

            this.showModal({
                iconClass: 'afop-icon-warning',
                icon: '<i class="fa-solid fa-repeat"></i>',
                title: title,
                message: msg,
                actions: actionsHtml
            });

            // Confirm click handler
            $(document).off('click', '#afop-btn-repeat-confirm').on('click', '#afop-btn-repeat-confirm', function () {
                self.repeatConfirmed = true;
                self.hideModal();

                // Append hidden confirmation flag to checkout form
                if (!$('input[name="afop_repeat_confirmed"]').length) {
                    $('form.checkout').append('<input type="hidden" name="afop_repeat_confirmed" value="1">');
                } else {
                    $('input[name="afop_repeat_confirmed"]').val('1');
                }

                // Trigger submission
                $('form.checkout').submit();
            });

            // Cancel click handler
            $(document).off('click', '#afop-btn-repeat-cancel').on('click', '#afop-btn-repeat-cancel', function () {
                self.hideModal();
            });
        }
    };

    $(document).ready(function () {
        AFOP.init();
    });

})(jQuery);
