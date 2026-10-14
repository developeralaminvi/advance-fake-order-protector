<?php
/**
 * Real-Time Incomplete Order / Abandoned Checkout Capture Engine
 * Captures checkout details ONLY when an 11-digit phone number is entered.
 * Automatically marks as converted/removes when order is placed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AFOP_Incomplete_Orders {

    public function __construct() {
        // AJAX endpoint for live checkout data capture
        add_action('wp_ajax_afop_capture_incomplete_order', array($this, 'ajax_capture_incomplete_order'));
        add_action('wp_ajax_nopriv_afop_capture_incomplete_order', array($this, 'ajax_capture_incomplete_order'));

        // Handle order placement - convert/clean incomplete order
        add_action('woocommerce_checkout_order_processed', array($this, 'handle_order_converted'), 10, 3);

        // Admin AJAX Actions
        add_action('wp_ajax_afop_delete_incomplete_ajax', array($this, 'ajax_delete_incomplete'));
        add_action('wp_ajax_afop_mark_recovered_ajax', array($this, 'ajax_mark_recovered'));
    }

    /**
     * AJAX endpoint to capture incomplete checkout details
     */
    public function ajax_capture_incomplete_order() {
        check_ajax_referer('afop_frontend_nonce', 'nonce');

        if (get_option('afop_enable_incomplete_capture', 'no') !== 'yes') {
            wp_send_json_error(array('message' => 'Incomplete capture disabled.'));
        }

        if (empty($_POST['capture_consent']) || $_POST['capture_consent'] !== 'yes') {
            wp_send_json_error(array('message' => 'Checkout recovery consent is required.'), 403);
        }
        if (!function_exists('WC') || !WC()->session || !WC()->cart || WC()->cart->is_empty()) {
            wp_send_json_error(array('message' => 'An active cart is required.'), 400);
        }
        WC()->session->set_customer_session_cookie(true);
        $session_key = hash_hmac('sha256', (string) WC()->session->get_customer_id(), wp_salt('auth'));
        $rate_key = 'afop_capture_' . md5(AFOP_Blocklist::get_client_ip());
        $attempts = (int) get_transient($rate_key);
        if ($attempts >= 60) {
            wp_send_json_error(array('message' => 'Please try again later.'), 429);
        }
        set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

        // Strict Requirement: MUST have a valid 11 digit BD phone number!
        $validation = AFOP_Validator::is_valid_bd_phone($phone);
        if (!$validation['valid']) {
            wp_send_json_error(array('message' => 'Valid 11 digit phone number required to record lead.'));
        }

        $normalized_phone = $validation['normalized'];

        // Get checkout fields
        $name        = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $email       = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $address     = isset($_POST['address']) ? sanitize_textarea_field(wp_unslash($_POST['address'])) : '';
        $city        = isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : '';
        // Extract Cart Data
        $cart_items = array();
        $cart_total = 0.00;

        if (WC()->cart) {
            $cart_total = WC()->cart->get_total('edit');
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product = $cart_item['data'];
                if ($product) {
                    $cart_items[] = array(
                        'product_id'   => $cart_item['product_id'],
                        'variation_id' => $cart_item['variation_id'],
                        'name'         => $product->get_name(),
                        'quantity'     => $cart_item['quantity'],
                        'price'        => wc_price($product->get_price()),
                        'total'        => wc_price($cart_item['line_total']),
                        'image'        => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail')
                    );
                }
            }
        }

        $name = wp_html_excerpt($name, 150);
        $city = wp_html_excerpt($city, 100);
        $address = wp_html_excerpt($address, 2000);
        if (strlen($email) > 150) { $email = ''; }

        global $wpdb;
        $table = $wpdb->prefix . 'afop_incomplete_orders';
        $client_ip = AFOP_Blocklist::get_client_ip();

        // Check if an incomplete record already exists for this phone or session
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared operations on plugin-owned recovery/blocklist tables require current records and counts after admin or checkout writes.
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM %i WHERE phone = %s AND session_key = %s AND status = 'incomplete' ORDER BY id DESC LIMIT 1", $table,
            $normalized_phone,
            $session_key
        ));

        if ($existing) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared operations on plugin-owned recovery/blocklist tables require current records and counts after admin or checkout writes.
            $result = $wpdb->update(
                $table,
                array(
                    'name'        => $name,
                    'email'       => $email,
                    'address'     => $address,
                    'city'        => $city,
                    'cart_data'   => wp_json_encode($cart_items),
                    'cart_total'  => $cart_total,
                    'ip_address'  => $client_ip,
                    'updated_at'  => current_time('mysql')
                ),
                array('id' => $existing->id),
                array('%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s'),
                array('%d')
            );
            $lead_id = $existing->id;
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared operations on plugin-owned recovery/blocklist tables require current records and counts after admin or checkout writes.
            $result = $wpdb->insert(
                $table,
                array(
                    'session_key' => $session_key,
                    'phone'       => $normalized_phone,
                    'name'        => $name,
                    'email'       => $email,
                    'address'     => $address,
                    'city'        => $city,
                    'cart_data'   => wp_json_encode($cart_items),
                    'cart_total'  => $cart_total,
                    'ip_address'  => $client_ip,
                    'status'      => 'incomplete',
                    'created_at'  => current_time('mysql'),
                    'updated_at'  => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s')
            );
            $lead_id = $wpdb->insert_id;
        }

        if ($result === false) { wp_send_json_error(array('message' => 'Could not save checkout recovery details. Please try again.'), 500); }
        wp_send_json_success(array('saved' => true));
    }

    /**
     * Mark incomplete order as converted when the order is successfully placed
     */
    public function handle_order_converted($order_id, $posted_data, $order) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        $phone = AFOP_Validator::normalize_phone($order->get_billing_phone());
        if (empty($phone)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'afop_incomplete_orders';

        // Update all incomplete records for this phone to converted
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared operations on plugin-owned recovery/blocklist tables require current records and counts after admin or checkout writes.
        $wpdb->update(
            $table,
            array(
                'status'     => 'converted',
                'order_id'   => $order_id,
                'updated_at' => current_time('mysql')
            ),
            array('phone' => $phone, 'status' => 'incomplete'),
            array('%s', '%d', '%s'),
            array('%s', '%s')
        );
    }

    /**
     * Admin AJAX to delete an incomplete record
     */
    public function ajax_delete_incomplete() {
        check_ajax_referer('afop_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized.'));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(array('message' => 'Invalid ID.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'afop_incomplete_orders';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared operations on plugin-owned recovery/blocklist tables require current records and counts after admin or checkout writes.
        $wpdb->delete($table, array('id' => $id), array('%d'));

        wp_send_json_success(array('message' => __('Incomplete lead deleted.', 'advance-fake-order-protector')));
    }

    /**
     * Admin AJAX to mark incomplete record as recovered
     */
    public function ajax_mark_recovered() {
        check_ajax_referer('afop_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized.'));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(array('message' => 'Invalid ID.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'afop_incomplete_orders';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared operations on plugin-owned recovery/blocklist tables require current records and counts after admin or checkout writes.
        $wpdb->update(
            $table,
            array('status' => 'recovered', 'updated_at' => current_time('mysql')),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );

        wp_send_json_success(array('message' => __('Lead marked as recovered!', 'advance-fake-order-protector')));
    }
}
