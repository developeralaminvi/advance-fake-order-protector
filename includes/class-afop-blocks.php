<?php
/** WooCommerce Checkout Block and Store API integration. GPLv2 or later. */
if (!defined('ABSPATH')) { exit; }

class AFOP_Blocks {
    const CONSENT_FIELD = 'afop/recovery-consent';

    public function __construct() {
        add_action('woocommerce_init', array($this, 'register_consent'));
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'validate_request'), 5, 2);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'validate_order'), 5);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'record_order'), 20);
    }

    public function register_consent() {
        if (get_option('afop_enable_incomplete_capture', 'no') !== 'yes' || !function_exists('woocommerce_register_additional_checkout_field')) { return; }
        woocommerce_register_additional_checkout_field(array(
            'id' => self::CONSENT_FIELD,
            'label' => __('I agree that this store may save my contact details and cart before I place an order, to help me complete my purchase.', 'advance-fake-order-protector'),
            'location' => 'order',
            'type' => 'checkbox',
            'required' => false,
        ));
    }

    public function validate_request($order, $request) {
        // PUT/PATCH requests contain incomplete draft fields. Only a final POST is an order attempt.
        if ($request->get_method() !== 'POST') { return; }
        $this->validate_order($order);
    }

    public function validate_order($order) {
        $phone = $order->get_billing_phone();
        if (get_option('afop_enable_bd_validation', 'yes') === 'yes') {
            $validation = AFOP_Validator::is_valid_bd_phone($phone);
            if (!$validation['valid']) {
                $this->reject('afop_invalid_phone', get_option('afop_invalid_phone_msg', __('Please enter a valid Bangladeshi mobile number.', 'advance-fake-order-protector')));
            }
        }
        // Use the current request IP, not an order or client-supplied forwarded header.
        $ip = AFOP_Blocklist::get_client_ip();
        if (AFOP_Blocklist::is_blocked('phone', $phone) || AFOP_Blocklist::is_blocked('ip', $ip)) {
            $this->reject('afop_blocked', get_option('afop_blocked_msg', __('This checkout is blocked. Please contact store support.', 'advance-fake-order-protector')));
        }
        $products = array();
        foreach ($order->get_items() as $item) {
            $products[] = $item->get_product_id();
            if ($item->get_variation_id()) { $products[] = $item->get_variation_id(); }
        }
        $repeat = AFOP_Repeat_Order::check_repeat_order($phone, $ip, array_unique($products), $order->get_id());
        if ($repeat['is_repeat']) {
            $message = get_option('afop_repeat_order_msg', __('A recent order already contains this product. Please contact store support.', 'advance-fake-order-protector'));
            $this->reject('afop_repeat_order', str_replace('{minutes}', (string) $repeat['minutes_ago'], $message));
        }
    }

    private function reject($code, $message) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(esc_html($code), esc_html(wp_strip_all_tags($message)), 400);
    }

    public function record_order($order) {
        $plugin = afop_get_plugin();
        $plugin->repeat_order->log_placed_order($order->get_id(), array(), $order);
        $plugin->incomplete_orders->handle_order_converted($order->get_id(), array(), $order);
        // Retain consent on this order, but require a fresh choice for the next cart.
        if (get_option('afop_enable_incomplete_capture', 'no') === 'yes' && WC()->customer) {
            WC()->customer->update_meta_data(\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::OTHER_FIELDS_PREFIX . self::CONSENT_FIELD, '0');
            WC()->customer->save();
        }
    }
}
