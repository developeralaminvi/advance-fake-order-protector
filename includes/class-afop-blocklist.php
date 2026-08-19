<?php
/**
 * IP and Phone Number Blocklist Engine
 * Handles blacklisting, unblacklisting, and checkout security enforcement
 */

if (!defined('ABSPATH')) {
    exit;
}

class AFOP_Blocklist {

    public function __construct() {
        // Enforce blocklist during checkout process
        add_action('woocommerce_checkout_process', array($this, 'enforce_checkout_blocklist'), 5);

        // Pre-check blocked status on checkout load or AJAX submit
        add_action('wp_ajax_afop_check_blocked_ajax', array($this, 'ajax_check_blocked'));
        add_action('wp_ajax_nopriv_afop_check_blocked_ajax', array($this, 'ajax_check_blocked'));

        // Admin AJAX to toggle block status (used in Orders list & single order page)
        add_action('wp_ajax_afop_toggle_block_ajax', array($this, 'ajax_toggle_block'));
        add_action('wp_ajax_afop_add_block_ajax', array($this, 'ajax_add_block'));
        add_action('wp_ajax_afop_bulk_block_ajax', array($this, 'ajax_bulk_block'));
        add_action('wp_ajax_afop_delete_block_ajax', array($this, 'ajax_delete_block'));
        add_action('wp_ajax_afop_bulk_delete_block_ajax', array($this, 'ajax_bulk_delete_block'));
        add_action('wp_ajax_afop_import_blocklist_ajax', array($this, 'ajax_import_blocklist'));

        // CSV Export Admin Post Action
        add_action('admin_post_afop_export_blocklist_csv', array($this, 'handle_csv_export'));
    }

    /**
     * Get real client IP address
     *
     * @return string
     */
    public static function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );

        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        return $ip;
                    }
                }
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '127.0.0.1';
    }

    /**
     * Check if a specific IP or Phone is blocked
     *
     * @param string $type 'phone' or 'ip'
     * @param string $value
     * @return bool
     */
    public static function is_blocked($type, $value) {
        if (get_option('afop_enable_blocklist', 'yes') !== 'yes') {
            return false;
        }

        if (empty($value)) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'afop_blocklist';

        if ($type === 'phone') {
            $value = AFOP_Validator::normalize_phone($value);
        } else {
            $value = trim($value);
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE type = %s AND value = %s LIMIT 1",
            $type,
            $value
        ));

        if ($row && $row->status === 'blocked') {
            return true;
        }

        return false;
    }

    /**
     * Block a Phone or IP
     *
     * @param string $type 'phone' or 'ip'
     * @param string $value
     * @param string $reason
     * @param string $blocked_by
     * @return bool
     */
    public static function block($type, $value, $reason = '', $blocked_by = 'admin') {
        global $wpdb;
        $table = $wpdb->prefix . 'afop_blocklist';

        if ($type === 'phone') {
            $value = AFOP_Validator::normalize_phone($value);
        } else {
            $value = trim($value);
        }

        if (empty($value)) {
            return false;
        }

        $exists = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE type = %s AND value = %s LIMIT 1",
            $type,
            $value
        ));

        if ($exists) {
            $wpdb->update(
                $table,
                array(
                    'status'     => 'blocked',
                    'reason'     => $reason,
                    'blocked_by' => $blocked_by,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $exists->id),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'type'       => $type,
                    'value'      => $value,
                    'reason'     => $reason,
                    'blocked_by' => $blocked_by,
                    'status'     => 'blocked',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }

        return true;
    }

    /**
     * Unblock a Phone or IP
     *
     * @param string $type 'phone' or 'ip'
     * @param string $value
     * @return bool
     */
    public static function unblock($type, $value) {
        global $wpdb;
        $table = $wpdb->prefix . 'afop_blocklist';

        if ($type === 'phone') {
            $value = AFOP_Validator::normalize_phone($value);
        } else {
            $value = trim($value);
        }

        if (empty($value)) {
            return false;
        }

        $wpdb->update(
            $table,
            array(
                'status'     => 'unblocked',
                'updated_at' => current_time('mysql')
            ),
            array('type' => $type, 'value' => $value),
            array('%s', '%s'),
            array('%s', '%s')
        );

        return true;
    }

    /**
     * Toggle block state
     *
     * @param string $type
     * @param string $value
     * @return bool New blocked state (true = now blocked, false = now unblocked)
     */
    public static function toggle($type, $value) {
        if (self::is_blocked($type, $value)) {
            self::unblock($type, $value);
            return false;
        } else {
            self::block($type, $value, 'Blocked from Orders Table');
            return true;
        }
    }

    /**
     * Enforce blocklist during checkout submission
     */
    public function enforce_checkout_blocklist() {
        if (get_option('afop_enable_blocklist', 'yes') !== 'yes') {
            return;
        }

        // 1. Check IP
        $client_ip = self::get_client_ip();
        if (self::is_blocked('ip', $client_ip)) {
            $blocked_msg = get_option('afop_blocked_msg', 'দুঃখিত, আপনার আইপি বা মোবাইল নম্বরটি নিরাপত্তা কারণে ব্লক করা আছে। সাহায্যের জন্য আমাদের সাপোর্টে যোগাযোগ করুন।');
            wc_add_notice(esc_html($blocked_msg), 'error');
            return;
        }

        // 2. Check Phone
        $billing_phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
        if (!empty($billing_phone)) {
            $normalized_phone = AFOP_Validator::normalize_phone($billing_phone);
            if (self::is_blocked('phone', $normalized_phone)) {
                $blocked_msg = get_option('afop_blocked_msg', 'দুঃখিত, আপনার আইপি বা মোবাইল নম্বরটি নিরাপত্তা কারণে ব্লক করা আছে। সাহায্যের জন্য আমাদের সাপোর্টে যোগাযোগ করুন।');
                wc_add_notice(esc_html($blocked_msg), 'error');
            }
        }
    }

    /**
     * AJAX endpoint to pre-check if current user/phone is blocked
     */
    public function ajax_check_blocked() {
        check_ajax_referer('afop_frontend_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $client_ip = self::get_client_ip();

        $ip_blocked = self::is_blocked('ip', $client_ip);
        $phone_blocked = false;

        if (!empty($phone)) {
            $normalized = AFOP_Validator::normalize_phone($phone);
            $phone_blocked = self::is_blocked('phone', $normalized);
        }

        if ($ip_blocked || $phone_blocked) {
            wp_send_json_success(array(
                'is_blocked' => true,
                'type'       => $ip_blocked ? 'ip' : 'phone',
                'title'      => get_option('afop_blocked_title', 'অর্ডার ব্লক করা হয়েছে!'),
                'message'    => get_option('afop_blocked_msg', 'দুঃখিত, আপনার আইপি বা মোবাইল নম্বরটি নিরাপত্তা কারণে ব্লক করা আছে। সাহায্যের জন্য আমাদের সাপোর্টে যোগাযোগ করুন।')
            ));
        }

        wp_send_json_success(array('is_blocked' => false));
    }

    /**
     * AJAX endpoint for Admin to toggle block/unblock (Orders List & Details)
     */
    public function ajax_toggle_block() {
        check_ajax_referer('afop_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized user'));
        }

        $type  = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
        $value = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';

        if (!in_array($type, array('phone', 'ip')) || empty($value)) {
            wp_send_json_error(array('message' => 'Invalid parameters.'));
        }

        $is_now_blocked = self::toggle($type, $value);
        $clean_val = ($type === 'phone') ? AFOP_Validator::normalize_phone($value) : $value;

        $msg = $is_now_blocked
            ? sprintf(__('🚫 %s "%s" সফলভাবে ব্লক করা হয়েছে!', 'advance-fake-order-protector'), ucfirst($type), $clean_val)
            : sprintf(__('✅ %s "%s" আনব্লক করা হয়েছে!', 'advance-fake-order-protector'), ucfirst($type), $clean_val);

        wp_send_json_success(array(
            'is_blocked' => $is_now_blocked,
            'type'       => $type,
            'value'      => $clean_val,
            'message'    => $msg
        ));
    }

    /**
     * Admin AJAX to manually add a block record
     */
    public function ajax_add_block() {
        check_ajax_referer('afop_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized user'));
        }

        $type   = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'phone';
        $value  = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : 'Manual Admin Block';

        if (empty($value)) {
            wp_send_json_error(array('message' => 'Value cannot be empty.'));
        }

        if ($type === 'phone') {
            $value = AFOP_Validator::normalize_phone($value);
        }

        self::block($type, $value, $reason, wp_get_current_user()->user_login);

        wp_send_json_success(array(
            'message' => sprintf(__('%s successfully added to blocklist!', 'advance-fake-order-protector'), ucfirst($type))
        ));
    }

    /**
     * Admin AJAX to bulk add multiple phone numbers or IPs
     */
    public function ajax_bulk_block() {
        check_ajax_referer('afop_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized user'));
        }

        $type        = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'phone';
        $bulk_values = isset($_POST['bulk_values']) ? sanitize_textarea_field(wp_unslash($_POST['bulk_values'])) : '';
        $reason      = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : 'Bulk Admin Block';

        if (empty($bulk_values)) {
            wp_send_json_error(array('message' => 'Please provide numbers or IPs to block.'));
        }

        // Split by newlines, commas, or semicolons
        $lines = preg_split('/[\r\n,;]+/', $bulk_values);
        $count = 0;
        $admin_user = wp_get_current_user()->user_login;

        foreach ($lines as $line) {
            $val = trim($line);
            if (empty($val)) continue;

            if ($type === 'phone') {
                $val = AFOP_Validator::normalize_phone($val);
                if (strlen($val) >= 11) {
                    self::block('phone', $val, $reason, $admin_user);
                    $count++;
                }
            } else {
                if (filter_var($val, FILTER_VALIDATE_IP)) {
                    self::block('ip', $val, $reason, $admin_user);
                    $count++;
                }
            }
        }

        wp_send_json_success(array(
            'count'   => $count,
            'message' => sprintf(__('%d %s entries successfully added to blocklist!', 'advance-fake-order-protector'), $count, ucfirst($type))
        ));
    }

    /**
     * Admin AJAX to delete a single block record
     */
    public function ajax_delete_block() {
        check_ajax_referer('afop_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized user'));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(array('message' => 'Invalid ID.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'afop_blocklist';
        $wpdb->delete($table, array('id' => $id), array('%d'));

        wp_send_json_success(array('message' => __('Record removed from blocklist.', 'advance-fake-order-protector')));
    }

    /**
     * Admin AJAX to bulk delete selected blocklist records
     */
    public function ajax_bulk_delete_block() {
        check_ajax_referer('afop_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized user'));
        }

        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();

        if (empty($ids)) {
            wp_send_json_error(array('message' => 'No records selected.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'afop_blocklist';
        $ids_placeholder = implode(',', array_fill(0, count($ids), '%d'));

        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$ids_placeholder})", $ids));

        wp_send_json_success(array(
            'message' => sprintf(__('%d records removed from blocklist.', 'advance-fake-order-protector'), count($ids))
        ));
    }

    /**
     * Export all blocklist entries to CSV
     */
    public function handle_csv_export() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('afop_export_blocklist_nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'afop_blocklist';
        $results = $wpdb->get_results("SELECT type, value, reason, status, blocked_by, created_at FROM {$table} ORDER BY id DESC", ARRAY_A);

        $filename = 'afop_blocklist_export_' . date('Y-m-d_H-i') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Output CSV Header
        fputcsv($output, array('Type', 'Value', 'Reason', 'Status', 'Blocked By', 'Date Added'));

        if (!empty($results)) {
            foreach ($results as $row) {
                fputcsv($output, array(
                    $row['type'],
                    $row['value'],
                    $row['reason'],
                    $row['status'],
                    $row['blocked_by'],
                    $row['created_at']
                ));
            }
        }

        fclose($output);
        exit;
    }

    /**
     * Admin AJAX to import blocklist from uploaded CSV/TXT
     */
    public function ajax_import_blocklist() {
        check_ajax_referer('afop_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized user'));
        }

        if (empty($_FILES['file']) || !isset($_FILES['file']['tmp_name'])) {
            wp_send_json_error(array('message' => 'Please upload a valid CSV or TXT file.'));
        }

        $file_tmp = $_FILES['file']['tmp_name'];
        $handle = fopen($file_tmp, 'r');
        if (!$handle) {
            wp_send_json_error(array('message' => 'Could not read uploaded file.'));
        }

        $imported = 0;
        $admin_user = wp_get_current_user()->user_login;
        $row_index = 0;

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $row_index++;
            if ($row_index === 1 && (strtolower(trim($data[0])) === 'type' || strtolower(trim($data[0])) === 'value')) {
                continue; // Skip header row
            }

            if (empty($data[0])) continue;

            $col0 = trim($data[0]);
            $col1 = isset($data[1]) ? trim($data[1]) : '';
            $col2 = isset($data[2]) ? trim($data[2]) : 'CSV Import';

            // Detect if row format is [Type, Value, Reason] or [Value, Reason]
            if (in_array(strtolower($col0), array('phone', 'ip'))) {
                $type = strtolower($col0);
                $value = $col1;
                $reason = $col2;
            } else {
                // Auto detect type
                $value = $col0;
                $reason = $col1 ?: 'CSV Import';
                $type = (filter_var($value, FILTER_VALIDATE_IP)) ? 'ip' : 'phone';
            }

            if ($type === 'phone') {
                $value = AFOP_Validator::normalize_phone($value);
            }

            if (!empty($value)) {
                self::block($type, $value, $reason, $admin_user);
                $imported++;
            }
        }

        fclose($handle);

        wp_send_json_success(array(
            'imported' => $imported,
            'message'  => sprintf(__('Successfully imported %d entries into the blocklist!', 'advance-fake-order-protector'), $imported)
        ));
    }
}
