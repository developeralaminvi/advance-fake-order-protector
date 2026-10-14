/** Checkout Block integration using the WooCommerce cart, checkout and validation stores. GPLv2 or later. */
(function ($, data) {
    'use strict';
    if (!data || !window.afop_data || !afop_data.is_blocks_checkout || !window.afopFrontend) return;
    var frontend = window.afopFrontend;
    var lastSnapshot = '';
    var timer;
    var revision = 0;
    var error = null;
    var lastModal = '';
    var noticeId = 'afop-checkout-protection';

    function clearError() {
        error = null;
        data.dispatch('wc/store/validation').clearValidationError(noticeId);
        data.dispatch('core/notices').removeNotice(noticeId, 'wc/checkout');
    }

    function setError(type, details, key, showModal) {
        var openModal = showModal && lastModal !== key;
        if (openModal) lastModal = key;
        error = { type: type, details: details, notified: true };
        data.dispatch('wc/store/validation').setValidationErrors({
            'afop-checkout-protection': { message: details.message, hidden: false }
        });
        data.dispatch('core/notices').createErrorNotice(details.message, { id: noticeId, context: 'wc/checkout', isDismissible: false });
        if (openModal) {
            if (type === 'blocked') frontend.showBlockedModal(details.title, details.message);
            else if (type === 'repeat') frontend.showRepeatOrderModal(details);
            else frontend.showInvalidPhoneModal(details.message);
        }
    }

    function check(snapshot, key, token, modalKey) {
        clearError();
        var phone = snapshot.billing.phone || '';
        if (afop_data.enable_bd_validation === 'yes') {
            var validation = frontend.validateBDPhone(phone);
            if (!validation.valid) {
                if (!phone) {
                    error = { type: 'phone', details: { message: afop_data.i18n.invalid_phone_msg }, notified: false };
                    data.dispatch('wc/store/validation').setValidationErrors({
                        'afop-checkout-protection': { message: afop_data.i18n.invalid_phone_msg, hidden: true }
                    });
                    return;
                }
                setError('phone', { message: afop_data.i18n.invalid_phone_msg }, modalKey, false);
                return;
            }
        }
        $.post(afop_data.ajax_url, { action: 'afop_check_blocked_ajax', nonce: afop_data.nonce, phone: phone }).done(function (res) {
            if (token !== revision) return;
            if (res.success && res.data && res.data.is_blocked) {
                setError('blocked', res.data, modalKey, true);
                return;
            }
            if (afop_data.enable_repeat_check === 'yes' && phone) {
                $.post(afop_data.ajax_url, { action: 'afop_check_repeat_order_ajax', nonce: afop_data.nonce, phone: phone }).done(function (repeat) {
                    if (token === revision && repeat.success && repeat.data && repeat.data.is_repeat) {
                        setError('repeat', repeat.data, modalKey, true);
                    }
                });
            }
        });
        // No lead is saved unless the separate optional consent checkbox is checked.
        if (afop_data.enable_incomplete === 'yes' && snapshot.consent && frontend.validateBDPhone(phone).valid) {
            var billing = snapshot.billing;
            $.post(afop_data.ajax_url, {
                action: 'afop_capture_incomplete_order', nonce: afop_data.nonce, capture_consent: 'yes', phone: phone,
                name: ((billing.first_name || '') + ' ' + (billing.last_name || '')).trim(),
                email: billing.email || '', city: billing.city || '',
                address: ((billing.address_1 || '') + ' ' + (billing.address_2 || '')).trim()
            });
        }
    }

    function observe() {
        var cart = data.select('wc/store/cart');
        var checkout = data.select('wc/store/checkout');
        if (!cart || !checkout || !cart.getCustomerData || !checkout.getAdditionalFields) return;
        var customer = cart.getCustomerData();
        var additional = checkout.getAdditionalFields() || {};
        var snapshot = {
            billing: customer.billingAddress || {},
            items: (cart.getCartData().items || []).map(function (item) { return { id: item.id, quantity: item.quantity }; }),
            consent: additional['afop/recovery-consent'] === true
        };
        var key = JSON.stringify(snapshot);
        var modalKey = JSON.stringify({ phone: snapshot.billing.phone || '', items: snapshot.items });
        if (key !== lastSnapshot) {
            lastSnapshot = key;
            revision++;
            clearTimeout(timer);
            clearError();
            var token = revision;
            timer = setTimeout(function () { check(snapshot, key, token, modalKey); }, 650);
        }
        // The Store API repeats every check server-side before payment, including on network failure here.
        if (checkout.getCheckoutStatus() === 'before-processing' && error && lastModal !== modalKey) {
            setError(error.type, error.details, modalKey, true);
        }
        var fieldError = data.select('wc/store/validation').getValidationError(noticeId);
        if (error && !error.notified && fieldError && !fieldError.hidden) {
            setError(error.type, error.details, modalKey, true);
        }
    }

    $(function () {
        data.subscribe(observe);
        observe();
    });
})(jQuery, window.wp && window.wp.data);
