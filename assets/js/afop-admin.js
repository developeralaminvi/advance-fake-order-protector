/**
 * AFOP Admin Interactivity Script
 * Handles 1-click block/unblock toggles, toast alerts, multi-provider tabbed courier modal, bulk block, and CSV import/export.
 */

(function ($) {
    'use strict';

    if (typeof afop_admin_data === 'undefined') {
        return;
    }

    var AFOP_Admin = {
        currentModalPhone: '',
        currentModalName: '',
        currentProvider: 'bdcourier',
        modalProvidersData: {},

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
                            var $matchingBtns = $('.afop-toggle-block-btn[data-value="' + value + '"]');

                            if (isBlocked) {
                                $matchingBtns.removeClass('is-safe').addClass('is-blocked');
                                if (type === 'phone') {
                                    $matchingBtns.find('.afop-btn-icon').html('<i class="fa-solid fa-ban"></i>');
                                    $matchingBtns.find('.afop-btn-text').text('Blocked');
                                } else {
                                    $matchingBtns.find('.afop-btn-icon').html('<i class="fa-solid fa-ban"></i>');
                                    $matchingBtns.find('.afop-btn-text').text('IP Blocked');
                                }
                            } else {
                                $matchingBtns.removeClass('is-blocked').addClass('is-safe');
                                if (type === 'phone') {
                                    $matchingBtns.find('.afop-btn-icon').html('<i class="fa-solid fa-phone-slash"></i>');
                                    $matchingBtns.find('.afop-btn-text').text('Block Phone');
                                } else {
                                    $matchingBtns.find('.afop-btn-icon').html('<i class="fa-solid fa-globe"></i>');
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

            // Courier Provider Tabs Switch
            $(document).on('click', '.afop-courier-tab-btn', function () {
                var provider = $(this).data('provider');
                $('.afop-courier-tab-btn').removeClass('active');
                $(this).addClass('active');
                self.currentProvider = provider;

                if (self.modalProvidersData && self.modalProvidersData[provider]) {
                    self.renderCourierModalData(self.modalProvidersData[provider]);
                }
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
                                $btn.html('<i class="fa-solid fa-unlock"></i> Unblock This Phone').removeClass('button-danger');
                            } else {
                                $btn.html('<i class="fa-solid fa-ban"></i> Block This Phone').addClass('button-danger');
                            }
                            self.showToast(res.data.message);
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

                $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Testing connection...');
                $res.html('<span style="color: #64748b;">Checking API server...</span>');

                $.ajax({
                    url: afop_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'afop_test_courier_api',
                        nonce: afop_admin_data.nonce
                    },
                    success: function (res) {
                        $btn.prop('disabled', false).html('<i class="fa-solid fa-plug"></i> Test Courier API Connection');
                        if (res.success) {
                            $res.html('<span style="color: #16a34a; font-weight: 600;"><i class="fa-solid fa-circle-check"></i> ' + res.data.message + '</span>');
                        } else {
                            $res.html('<span style="color: #dc2626; font-weight: 600;"><i class="fa-solid fa-circle-xmark"></i> ' + res.data.message + '</span>');
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).html('<i class="fa-solid fa-plug"></i> Test Courier API Connection');
                        $res.html('<span style="color: #dc2626;"><i class="fa-solid fa-triangle-exclamation"></i> Server connection error.</span>');
                    }
                });
            });

            // 4. Blocklist Toolbar Toggle Buttons
            $('#afop-toggle-single-block').on('click', function () {
                $('#afop-single-block-panel').slideDown(200);
                $('#afop-bulk-block-panel').slideUp(200);
                $('#afop-import-block-panel').slideUp(200);
            });

            $('#afop-toggle-bulk-block').on('click', function () {
                $('#afop-bulk-block-panel').slideDown(200);
                $('#afop-single-block-panel').slideUp(200);
                $('#afop-import-block-panel').slideUp(200);
            });

            $('#afop-cancel-bulk-btn').on('click', function () {
                $('#afop-bulk-block-panel').slideUp(200);
                $('#afop-single-block-panel').slideDown(200);
            });

            $('#afop-toggle-import-block').on('click', function () {
                $('#afop-import-block-panel').slideDown(200);
                $('#afop-single-block-panel').slideUp(200);
                $('#afop-bulk-block-panel').slideUp(200);
            });

            $('#afop-cancel-import-btn').on('click', function () {
                $('#afop-import-block-panel').slideUp(200);
                $('#afop-single-block-panel').slideDown(200);
            });

            // Single Block Submit
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

            // Bulk Block Form Submit
            $('#afop-bulk-block-form').on('submit', function (e) {
                e.preventDefault();
                var type = $('#afop-bulk-block-type').val();
                var values = $('#afop-bulk-block-values').val();
                var reason = $('#afop-bulk-block-reason').val();

                if (!values) return;

                var $submitBtn = $(this).find('button[type="submit"]');
                $submitBtn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Blocking...');

                $.ajax({
                    url: afop_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'afop_bulk_block_ajax',
                        nonce: afop_admin_data.nonce,
                        type: type,
                        bulk_values: values,
                        reason: reason
                    },
                    success: function (res) {
                        $submitBtn.prop('disabled', false).html('<i class="fa-solid fa-ban"></i> Bulk Block All');
                        if (res.success) {
                            self.showToast(res.data.message);
                            setTimeout(function () { location.reload(); }, 700);
                        } else {
                            self.showToast(res.data.message, 'error');
                        }
                    }
                });
            });

            // Import CSV Form Submit
            $('#afop-import-block-form').on('submit', function (e) {
                e.preventDefault();
                var fileInput = $('#afop-import-file')[0];
                if (!fileInput.files.length) return;

                var formData = new FormData();
                formData.append('action', 'afop_import_blocklist_ajax');
                formData.append('nonce', afop_admin_data.nonce);
                formData.append('file', fileInput.files[0]);

                var $status = $('#afop-import-status');
                $status.html('<span style="color: #64748b;"><i class="fa-solid fa-spinner fa-spin"></i> Importing...</span>');

                $.ajax({
                    url: afop_admin_data.ajax_url,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (res) {
                        if (res.success) {
                            $status.html('<span style="color: #16a34a;"><i class="fa-solid fa-check"></i> ' + res.data.message + '</span>');
                            self.showToast(res.data.message);
                            setTimeout(function () { location.reload(); }, 900);
                        } else {
                            $status.html('<span style="color: #dc2626;">' + res.data.message + '</span>');
                        }
                    }
                });
            });

            // Select All Checkbox Handler
            $('#cb-select-all').on('change', function () {
                var checked = $(this).is(':checked');
                $('.afop-block-cb').prop('checked', checked);
                self.updateBulkDeleteButton();
            });

            $(document).on('change', '.afop-block-cb', function () {
                self.updateBulkDeleteButton();
            });

            // Bulk Delete Selected
            $('#afop-bulk-delete-btn').on('click', function (e) {
                e.preventDefault();
                var selectedIds = [];
                $('.afop-block-cb:checked').each(function () {
                    selectedIds.push($(this).val());
                });

                if (!selectedIds.length) return;
                if (!confirm(afop_admin_data.i18n.confirm_bulk_del || 'Delete selected records?')) return;

                $.ajax({
                    url: afop_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'afop_bulk_delete_block_ajax',
                        nonce: afop_admin_data.nonce,
                        ids: selectedIds
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

            // Single Delete Block Record
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

        updateBulkDeleteButton: function () {
            var count = $('.afop-block-cb:checked').length;
            if (count > 0) {
                $('#afop-bulk-delete-btn').html('<i class="fa-solid fa-trash-can"></i> Delete Selected (' + count + ')').show();
            } else {
                $('#afop-bulk-delete-btn').hide();
            }
        },

        openCourierModal: function (phone, name, forceRefresh) {
            var self = this;
            var $modal = $('#afop-admin-courier-modal');
            var $loading = $('#afop-courier-loading');
            var $content = $('#afop-courier-content');

            $('#afop-courier-customer-title').text(name || 'Customer');
            $('#afop-courier-phone-badge').html('<i class="fa-solid fa-phone"></i> ' + phone);

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
                    provider: 'all',
                    force_refresh: forceRefresh ? 'true' : 'false'
                },
                success: function (res) {
                    $loading.hide();
                    if (res.success && res.data) {
                        if (res.data.multi_provider && res.data.providers) {
                            self.modalProvidersData = res.data.providers;
                            var defaultProv = res.data.active_provider || 'bdcourier';
                            self.currentProvider = defaultProv;

                            $('.afop-courier-tab-btn').removeClass('active');
                            $('.afop-courier-tab-btn[data-provider="' + defaultProv + '"]').addClass('active');

                            if (self.modalProvidersData[defaultProv]) {
                                self.renderCourierModalData(self.modalProvidersData[defaultProv]);
                            }
                        } else {
                            self.renderCourierModalData(res.data);
                        }
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
                $badge.addClass('high').html('<i class="fa-solid fa-triangle-exclamation"></i> High Risk Alert');
            } else if (data.risk_level === 'medium') {
                $circle.addClass('risk-medium');
                $badge.addClass('medium').html('<i class="fa-solid fa-circle-exclamation"></i> Medium Risk');
            } else {
                $badge.addClass('safe').html('<i class="fa-solid fa-circle-check"></i> Safe Customer');
            }

            // Metrics Counts
            $('#afop-delivered-count').text(data.delivered || 0);
            $('#afop-returned-count').text(data.returned || 0);
            $('#afop-total-count').text(data.total_orders || 0);

            // Demo Notice
            if (data.is_demo && data.demo_notice) {
                $('#afop-demo-notice-banner').html('<i class="fa-solid fa-circle-info"></i> ' + data.demo_notice).show();
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
                $tbody.append('<tr><td colspan="5" style="text-align: center; color: #64748b;">No individual breakdown available</td></tr>');
            }

            // Update Block Phone button in modal
            var $blockBtn = $('#afop-modal-block-phone-btn');
            if (data.is_blocked) {
                $blockBtn.html('<i class="fa-solid fa-unlock"></i> Unblock This Phone').removeClass('button-danger');
            } else {
                $blockBtn.html('<i class="fa-solid fa-ban"></i> Block This Phone').addClass('button-danger');
            }
        },

        showToast: function (msg, type) {
            var $container = $('#afop-toast-container');
            if (!$container.length) {
                $container = $('<div id="afop-toast-container" class="afop-toast-container"></div>').appendTo('body');
            }

            var icon = (type === 'error') ? '<i class="fa-solid fa-circle-xmark"></i>' : '<i class="fa-solid fa-circle-check"></i>';
            var $toast = $('<div class="afop-toast">' + icon + ' <span>' + msg + '</span></div>');

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
