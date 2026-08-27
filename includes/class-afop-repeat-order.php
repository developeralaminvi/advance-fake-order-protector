<?php
/**
 * Duplicate / Repeat Order Detector
 * Prevents accidental or spam repeat orders of the same product within a configurable time window
 */

if (!defined('ABSPATH')) {
    exit;
}

class AFOP_Repeat_Order {

    public function __construct() {
        // Record order when placed
        add_action('woocommerce_checkout_order_processed', array($this, 'log_placed_order'), 20, 3);

        // Pre-check duplicate order via AJAX
        add_action('wp_ajax_afop_check_repeat_order_ajax', array($this, 'ajax_check_repeat_order'));
        add_action('wp_ajax_nopriv_afop_check_repeat_order_ajax', array($this, 'ajax_check_repeat_order'));

        // Server-side safety check
        add_action('woocommerce_checkout_process', array($this, 'validate_repeat_order_checkout'), 15);
    }

    /**
     * Get product IDs currently in cart
     *
     * @return array
     */
    public static function get_cart_product_ids() {
        $product_ids = array();
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (!empty($cart_item['product_id'])) {
                    $product_ids[] = intval($cart_item['product_id']);
                }
                if (!empty($cart_item['variation_id'])) {
                    $product_ids[] = intval($cart_item['variation_id']);
                }
            }
        }
        return array_unique($product_ids);
    }

    /**
     * Log newly created order into AFOP history table
     *
     * @param int $order_id
     * @param array $posted_data
     * @param WC_Order $order
     */
    public function log_placed_order($order_id, $posted_data, $order) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        $phone = $order->get_billing_phone();
        $normalized_phone = AFOP_Validator::normalize_phone($phone);
        $client_ip = $order->get_customer_ip_address();
        if (empty($client_ip)) {
            $client_ip = AFOP_Blocklist::get_client_ip();
        }

        $product_ids = array();
        foreach ($order->get_items() as $item) {
            $product_ids[] = $item->get_product_id();
            if ($item->get_variation_id()) {
                $product_ids[] = $item->get_variation_id();
            }
        }
        $product_ids = array_unique(array_filter($product_ids));

        global $wpdb;
        $table = $wpdb->prefix . 'afop_order_history';

        $wpdb->insert(
            $table,
            array(
                'order_id'     => $order_id,
                'phone'        => $normalized_phone,
                'ip_address'   => $client_ip,
                'product_ids'  => implode(',', $product_ids),
                'order_total'  => $order->get_total(),
                'created_at'   => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%f', '%s')
        );
    }

    /**
     * Check if a repeat order exists for this phone or IP within the time window
     *
     * @param string $phone
     * @param string $ip
     * @param array $cart_product_ids
     * @return array array('is_repeat' => bool, 'minutes_ago' => int, 'previous_order_id' => int)
     */
    public static function check_repeat_order($phone, $ip = '', $cart_product_ids = array()) {
        if (get_option('afop_enable_repeat_check', 'yes') !== 'yes') {
            return array('is_repeat' => false, 'minutes_ago' => 0, 'previous_order_id' => 0);
        }

        $normalized_phone = AFOP_Validator::normalize_phone($phone);
        if (empty($ip)) {
            $ip = AFOP_Blocklist::get_client_ip();
        }

        if (empty($cart_product_ids)) {
            $cart_product_ids = self::get_cart_product_ids();
        }

        if (empty($cart_product_ids)) {
            return array('is_repeat' => false, 'minutes_ago' => 0, 'previous_order_id' => 0);
        }

        $time_limit_minutes = intval(get_option('afop_repeat_time_limit', 60));
        if ($time_limit_minutes <= 0) {
            $time_limit_minutes = 60;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'afop_order_history';

        // Query recent orders within time window by phone or IP
        $cutoff_time = date('Y-m-d H:i:s', current_time('timestamp') - ($time_limit_minutes * 60));

        $query = $wpdb->prepare(
            "SELECT order_id, product_ids, created_at 
             FROM {$table} 
             WHERE (phone = %s OR ip_address = %s) 
               AND created_at >= %s 
             ORDER BY created_at DESC",
            $normalized_phone,
            $ip,
            $cutoff_time
        );

        $recent_orders = $wpdb->get_results($query);

        if (!$recent_orders) {
            return array('is_repeat' => false, 'minutes_ago' => 0, 'previous_order_id' => 0);
        }

        foreach ($recent_orders as $prev_order) {
            $prev_product_ids = array_map('intval', explode(',', $prev_order->product_ids));
            // Check if any product matches
            $common_products = array_intersect($cart_product_ids, $prev_product_ids);

            if (!empty($common_products)) {
                $order_timestamp = strtotime($prev_order->created_at);
                $minutes_ago = max(1, round((current_time('timestamp') - $order_timestamp) / 60));

                return array(
                    'is_repeat'         => true,
                    'minutes_ago'       => $minutes_ago,
                    'previous_order_id' => $prev_order->order_id
                );
            }
        }

        return array('is_repeat' => false, 'minutes_ago' => 0, 'previous_order_id' => 0);
    }

    /**
     * AJAX endpoint to check repeat order before checkout submit
     */
    public function ajax_check_repeat_order() {
        check_ajax_referer('afop_frontend_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $confirmed = isset($_POST['confirmed']) && $_POST['confirmed'] === 'yes';

        if ($confirmed) {
            wp_send_json_success(array('is_repeat' => false, 'bypassed' => true));
        }

        $check = self::check_repeat_order($phone);

        if ($check['is_repeat']) {
            $msg_template = get_option('afop_repeat_order_msg', 'আপনি {minutes} মিনিট আগে এই প্রোডাক্টটি অর্ডার করেছিলেন। পুনরায় একই প্রোডাক্ট অর্ডার করতে চাইলে অনুগ্রহ করে আমাদের হোয়াটসঅ্যাপে যোগাযোগ করুন।');
            $msg = str_replace('{minutes}', $check['minutes_ago'], $msg_template);

            wp_send_json_success(array(
                'is_repeat'     => true,
                'minutes_ago'   => $check['minutes_ago'],
                'title'         => get_option('afop_repeat_order_title', 'পুনরায় অর্ডার সংক্রান্ত তথ্য'),
                'message'       => $msg,
                'show_whatsapp' => true,
                'whatsapp_btn'  => get_option('afop_repeat_whatsapp_btn', 'হোয়াটসঅ্যাপে অর্ডার করুন'),
                'close_btn'     => 'বন্ধ করুন'
            ));
        }

        wp_send_json_success(array('is_repeat' => false));
    }

    /**
     * Server side verification during checkout submission
     */
    public function validate_repeat_order_checkout() {
        if (get_option('afop_enable_repeat_check', 'yes') !== 'yes') {
            return;
        }

        $billing_phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
        if (empty($billing_phone)) {
            return;
        }

        $check = self::check_repeat_order($billing_phone);

        if ($check['is_repeat']) {
            $msg_template = get_option('afop_repeat_order_msg', 'আপনি {minutes} মিনিট আগে এই প্রোডাক্টটি অর্ডার করেছিলেন। পুনরায় একই প্রোডাক্ট অর্ডার করতে চাইলে অনুগ্রহ করে আমাদের হোয়াটসঅ্যাপে যোগাযোগ করুন।');
            $msg = str_replace('{minutes}', $check['minutes_ago'], $msg_template);
            wc_add_notice(esc_html($msg), 'error');
        }
    }

    /**
     * Get all store orders placed by a customer phone number (HPOS + CPT + AFOP history)
     *
     * @param string $phone
     * @return array
     */
    public static function get_orders_by_phone($phone) {
        $normalized_phone = AFOP_Validator::normalize_phone($phone);
        if (empty($normalized_phone)) {
            return array();
        }

        $clean = preg_replace('/[^0-9]/', '', $normalized_phone);
        $short = (substr($clean, 0, 2) === '88' && strlen($clean) === 13) ? substr($clean, 2) : $clean;

        global $wpdb;
        $order_ids = array();

        // 1. Check HPOS table if HPOS is active
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $table_addresses = $wpdb->prefix . 'wc_order_addresses';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table_addresses}'") === $table_addresses) {
                $sql = $wpdb->prepare(
                    "SELECT DISTINCT order_id FROM {$table_addresses} WHERE address_type = 'billing' AND (phone LIKE %s OR phone LIKE %s)",
                    '%' . $wpdb->esc_like($short) . '%',
                    '%' . $wpdb->esc_like($clean) . '%'
                );
                $hpos_ids = $wpdb->get_col($sql);
                if (!empty($hpos_ids)) {
                    $order_ids = array_merge($order_ids, $hpos_ids);
                }
            }
        }

        // 2. Check Postmeta table (Classic CPT or fallback)
        $sql = $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_billing_phone' AND (meta_value LIKE %s OR meta_value LIKE %s)",
            '%' . $wpdb->esc_like($short) . '%',
            '%' . $wpdb->esc_like($clean) . '%'
        );
        $cpt_ids = $wpdb->get_col($sql);
        if (!empty($cpt_ids)) {
            $order_ids = array_merge($order_ids, $cpt_ids);
        }

        // 3. Check afop_order_history table as backup
        $table_history = $wpdb->prefix . 'afop_order_history';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_history}'") === $table_history) {
            $sql = $wpdb->prepare(
                "SELECT DISTINCT order_id FROM {$table_history} WHERE phone = %s OR phone = %s",
                $short,
                $clean
            );
            $history_ids = $wpdb->get_col($sql);
            if (!empty($history_ids)) {
                $order_ids = array_merge($order_ids, $history_ids);
            }
        }

        $order_ids = array_unique(array_filter(array_map('intval', $order_ids)));

        if (empty($order_ids)) {
            return array();
        }

        $orders_data = array();
        foreach ($order_ids as $id) {
            $order = wc_get_order($id);
            if (!$order) {
                continue;
            }

            $items = array();
            foreach ($order->get_items() as $item) {
                $items[] = array(
                    'name' => $item->get_name(),
                    'qty'  => $item->get_quantity()
                );
            }

            $edit_url = get_edit_post_link($id);
            if (empty($edit_url)) {
                $edit_url = admin_url('admin.php?page=wc-orders&action=edit&id=' . $id);
            }

            $date_obj = $order->get_date_created();
            $date_str = $date_obj ? $date_obj->date_i18n('Y-m-d H:i') : '';

            $orders_data[] = array(
                'id'           => $id,
                'number'       => $order->get_order_number(),
                'date'         => $date_str,
                'status'       => $order->get_status(),
                'status_name'  => wc_get_order_status_name($order->get_status()),
                'total'        => $order->get_formatted_order_total(),
                'raw_total'    => floatval($order->get_total()),
                'items'        => $items,
                'edit_url'     => $edit_url
            );
        }

        // Sort newest orders first
        usort($orders_data, function($a, $b) {
            return strcmp($b['date'], $a['date']);
        });

        return $orders_data;
    }
}
