/**
 * AFOP Admin Interactivity Script
 * Handles 1-click block/unblock toggles, toast alerts, courier ratio modal, and API test connections.
 */

(function ($) {
    'use strict';

    if (typeof afop_admin_data === 'undefined') {
        return;
    }

    var AFOP_Admin = {
        currentModalPhone: '',
        currentModalName: '',

        init: function () {
            this.bindEvents();
        },

        bindEvents: function () {
            var self = this;

            // 1. One-Click Block / Unblock Toggle in Orders List & Meta Box
            $(document).on('click', '.afop-toggle-block-btn', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var type = $btn.data('type');
                var value = $btn.data('value');

                if (!type || !value) return;

                $btn.prop('disabled', true).css('opacity', '0.6');

                $.ajax({
                    url: afop_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'afop_toggle_block_ajax',
                        nonce: afop_admin_data.nonce,
                        type: type,
                        value: value
                    },
                    success: function (res) {
                        $btn.prop('disabled', false).css('opacity', '1');
                        if (res.success && res.data) {
                            var isBlocked = res.data.is_blocked;
                            
                            // Update this button and any other buttons for the same value on page
                            var $matchingBtns = $('.afop-toggle-block-btn[data-value="' + value + '"]');

                            if (isBlocked) {
                                $matchingBtns.removeClass('is-safe').addClass('is-blocked');
                                if (type === 'phone') {
                                    $matchingBtns.find('.afop-btn-icon').text('🚫');
                                    $matchingBtns.find('.afop-btn-text').text('Blocked');
                                } else {
                                    $matchingBtns.find('.afop-btn-icon').text('🚫');
                                    $matchingBtns.find('.afop-btn-text').text('IP Blocked');
                                }
                            } else {
                                $matchingBtns.removeClass('is-blocked').addClass('is-safe');
                                if (type === 'phone') {
                                    $matchingBtns.find('.afop-btn-icon').text('📞');
                                    $matchingBtns.find('.afop-btn-text').text('Block Phone');
                                } else {
                                    $matchingBtns.find('.afop-btn-icon').text('🌐');
                                    $matchingBtns.find('.afop-btn-text').text('Block IP');
                                }
                            }

                            self.showToast(res.data.message || 'Updated successfully!');
                        } else {
                            self.showToast(res.data ? res.data.message : 'Error updating block state.', 'error');
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).css('opacity', '1');
                        self.showToast('Network error while toggling block status.', 'error');
                    }
                });
            });

            // 2. Open Courier Ratio Modal
            $(document).on('click', '.afop-check-courier-btn', function (e) {
                e.preventDefault();
                var phone = $(this).data('phone');
                var name = $(this).data('name') || 'Customer';

                if (!phone) return;

                self.currentModalPhone = phone;
                self.currentModalName = name;

                self.openCourierModal(phone, name, false);
            });

            // Close Courier Modal
            $(document).on('click', '#afop-close-courier-modal, #afop-modal-close-footer-btn', function () {
                self.closeCourierModal();
            });

            // Force Refresh Courier Data inside Modal
            $(document).on('click', '#afop-refresh-courier-btn', function () {
                if (self.currentModalPhone) {
                    self.openCourierModal(self.currentModalPhone, self.currentModalName, true);
                }
            });

            // Block Phone inside Courier Modal
            $(document).on('click', '#afop-modal-block-phone-btn', function () {
                if (!self.currentModalPhone) return;

                var $btn = $(this);
                $btn.prop('disabled', true);

                $.ajax({
                    url: afop_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'afop_toggle_block_ajax',
                        nonce: afop_admin_data.nonce,
                        type: 'phone',
                        value: self.currentModalPhone
                    },
                    success: function (res) {
                        $btn.prop('disabled', false);
                        if (res.success && res.data) {
                            if (res.data.is_blocked) {
                                $btn.text('✅ Unblock This Phone').removeClass('button-danger');
                            } else {
                                $btn.text('⛔ Block This Phone').addClass('button-danger');
                            }
                            self.showToast(res.data.message);
                            // Update table buttons
                            $('.afop-toggle-block-btn[data-value="' + self.currentModalPhone + '"]').trigger('afop_refresh_state');
                        }
                    }
                });
            });

            // 3. Test Courier API Connection (Settings Tab)
            $(document).on('click', '#afop-test-api-btn', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var $res = $('#afop-test-api-result');

                $btn.prop('disabled', true).text('Testing connection...');
                $res.html('<span style="color: #64748b;">⏳ Checking API server...</span>');

                $.ajax({
                    url: afop_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'afop_test_courier_api',
                        nonce: afop_admin_data.nonce
                    },
                    success: function (res) {
                        $btn.prop('disabled', false).text('🔌 Test Courier API Connection');
                        if (res.success) {
                            $res.html('<span style="color: #16a34a; font-weight: 600;">✅ ' + res.data.message + '</span>');
                        } else {
                            $res.html('<span style="color: #dc2626; font-weight: 600;">❌ ' + res.data.message + '</span>');
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).text('🔌 Test Courier API Connection');
                        $res.html('<span style="color: #dc2626;">❌ Server connection error.</span>');
                    }
                });
            });

            // 4. Add Blocklist Form Submit
            $('#afop-add-block-form').on('submit', function (e) {
                e.preventDefault();
                var type = $('#afop-new-block-type').val();
                var value = $.trim($('#afop-new-block-value').val());
                var reason = $.trim($('#afop-new-block-reason').val());

                if (!value) return;

                $.ajax({
                    url: afop_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'afop_add_block_ajax',
                        nonce: afop_admin_data.nonce,
                        type: type,
                        value: value,
                        reason: reason
                    },
                    success: function (res) {
                        if (res.success) {
                            self.showToast(res.data.message);
                            setTimeout(function () { location.reload(); }, 600);
                        } else {
                            self.showToast(res.data.message, 'error');
                        }
                    }
                });
            });

            // Delete Block Record
            $(document).on('click', '.afop-btn-delete-block', function (e) {
                e.preventDefault();
                if (!confirm(afop_admin_data.i18n.confirm_delete || 'Are you sure?')) return;

                var id = $(this).data('id');
                var $row = $('#afop-block-row-' + id);

                $.ajax({
                    url: afop_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'afop_delete_block_ajax',
                        nonce: afop_admin_data.nonce,
                        id: id
                    },
                    success: function (res) {
                        if (res.success) {
                            $row.fadeOut(300, function () { $(this).remove(); });
                            self.showToast(res.data.message);
                        }
                    }
                });
            });

            // Incomplete Leads: Delete Lead
            $(document).on('click', '.afop-btn-delete-lead', function (e) {
                e.preventDefault();
                if (!confirm(afop_admin_data.i18n.confirm_delete || 'Are you sure?')) return;

                var id = $(this).data('id');
                var $row = $('#afop-incomplete-row-' + id);

                $.ajax({
                    url: afop_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'afop_delete_incomplete_ajax',
                        nonce: afop_admin_data.nonce,
                        id: id
                    },
                    success: function (res) {
                        if (res.success) {
                            $row.fadeOut(300, function () { $(this).remove(); });
                            self.showToast(res.data.message);
                        }
                    }
                });
            });

            // Incomplete Leads: Mark Recovered
            $(document).on('click', '.afop-btn-mark-recovered', function (e) {
                e.preventDefault();
                if (!confirm(afop_admin_data.i18n.confirm_recovered || 'Mark as recovered?')) return;

                var id = $(this).data('id');

                $.ajax({
                    url: afop_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'afop_mark_recovered_ajax',
                        nonce: afop_admin_data.nonce,
                        id: id
                    },
                    success: function (res) {
                        if (res.success) {
                            self.showToast(res.data.message);
                            setTimeout(function () { location.reload(); }, 600);
                        }
                    }
                });
            });
        },

        openCourierModal: function (phone, name, forceRefresh) {
            var self = this;
            var $modal = $('#afop-admin-courier-modal');
            var $loading = $('#afop-courier-loading');
            var $content = $('#afop-courier-content');

            $('#afop-courier-customer-title').text(name || 'Customer');
            $('#afop-courier-phone-badge').text('📞 ' + phone);

            $modal.fadeIn(200);
            $loading.show();
            $content.hide();

            $.ajax({
                url: afop_admin_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'afop_get_courier_ratio_ajax',
                    nonce: afop_admin_data.nonce,
                    phone: phone,
                    force_refresh: forceRefresh ? 'true' : 'false'
                },
                success: function (res) {
                    $loading.hide();
                    if (res.success && res.data) {
                        self.renderCourierModalData(res.data);
                        $content.fadeIn(200);
                    } else {
                        self.showToast(res.data ? res.data.message : 'Error fetching courier data.', 'error');
                        self.closeCourierModal();
                    }
                },
                error: function () {
                    $loading.hide();
                    self.showToast('Network error while connecting to courier API.', 'error');
                    self.closeCourierModal();
                }
            });
        },

        closeCourierModal: function () {
            $('#afop-admin-courier-modal').fadeOut(200);
        },

        renderCourierModalData: function (data) {
            // Gauge & Percentage
            var rate = parseFloat(data.delivery_rate || 0);
            var returnRate = parseFloat(data.return_rate || 0);

            $('#afop-delivery-rate-text').text(rate + '%');
            $('#afop-return-rate-text').text(returnRate + '%');

            var $circle = $('#afop-gauge-circle');
            $circle.removeClass('risk-medium risk-high');

            var $badge = $('#afop-risk-badge');
            $badge.removeClass('safe medium high');

            if (data.risk_level === 'high') {
                $circle.addClass('risk-high');
                $badge.addClass('high').text('🔴 High Risk / Fraud Alert');
            } else if (data.risk_level === 'medium') {
                $circle.addClass('risk-medium');
                $badge.addClass('medium').text('🟡 Medium Return Risk');
            } else {
                $badge.addClass('safe').text('🟢 Safe Customer');
            }

            // Metrics Counts
            $('#afop-delivered-count').text(data.delivered || 0);
            $('#afop-returned-count').text(data.returned || 0);
            $('#afop-total-count').text(data.total_orders || 0);

            // Demo Notice
            if (data.is_demo && data.demo_notice) {
                $('#afop-demo-notice-banner').html('ℹ️ ' + data.demo_notice).show();
            } else {
                $('#afop-demo-notice-banner').hide();
            }

            // Courier Breakdown Table
            var $tbody = $('#afop-courier-breakdown-tbody');
            $tbody.empty();

            if (data.couriers && data.couriers.length > 0) {
                $.each(data.couriers, function (i, c) {
                    var successPercent = (c.total > 0) ? Math.round((c.delivered / c.total) * 100) : 0;
                    var rowHtml = '<tr>' +
                        '<td><strong>' + c.name + '</strong></td>' +
                        '<td>' + c.total + '</td>' +
                        '<td><span style="color: #16a34a; font-weight: 600;">' + c.delivered + '</span></td>' +
                        '<td><span style="color: #dc2626; font-weight: 600;">' + c.returned + '</span></td>' +
                        '<td><strong>' + successPercent + '%</strong></td>' +
                        '</tr>';
                    $tbody.append(rowHtml);
                });
            } else {
                $tbody.append('<tr><td colspan="5" style="text-align: center; color: #64748b;">No courier details available</td></tr>');
            }

            // Update Block Phone button in modal
            var $blockBtn = $('#afop-modal-block-phone-btn');
            if (data.is_blocked) {
                $blockBtn.text('✅ Unblock This Phone').removeClass('button-danger');
            } else {
                $blockBtn.text('⛔ Block This Phone').addClass('button-danger');
            }
        },

        showToast: function (msg, type) {
            var $container = $('#afop-toast-container');
            if (!$container.length) {
                $container = $('<div id="afop-toast-container" class="afop-toast-container"></div>').appendTo('body');
            }

            var icon = (type === 'error') ? '⚠️' : '✅';
            var $toast = $('<div class="afop-toast">' + icon + ' ' + msg + '</div>');

            if (type === 'error') {
                $toast.css('background', '#dc2626');
            }

            $container.append($toast);

            setTimeout(function () {
                $toast.addClass('afop-toast-out');
                setTimeout(function () { $toast.remove(); }, 300);
            }, 3500);
        }
    };

    $(document).ready(function () {
        AFOP_Admin.init();
    });

})(jQuery);
