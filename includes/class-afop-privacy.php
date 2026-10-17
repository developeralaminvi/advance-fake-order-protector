<?php
/** Privacy, consent and local data retention. GPLv2 or later. */
if (!defined('ABSPATH')) { exit; }

class AFOP_Privacy {
    public function __construct() {
        add_action('afop_daily_cleanup', array($this, 'cleanup'));
        add_action('woocommerce_after_checkout_billing_form', array($this, 'consent_field'));
        add_action('admin_init', array($this, 'privacy_policy'));
        add_filter('wp_privacy_personal_data_exporters', array($this, 'exporters'));
        add_filter('wp_privacy_personal_data_erasers', array($this, 'erasers'));
    }

    public function consent_field() {
        if (get_option('afop_enable_incomplete_capture', 'no') !== 'yes') { return; }
        echo '<p class="form-row form-row-wide"><label><input type="checkbox" id="afop-recovery-consent" name="afop_recovery_consent" value="yes"> ';
        echo esc_html__('I agree that this store may save my contact details and cart before I place an order, to help me complete my purchase.', 'advance-fake-order-protector');
        $url = get_privacy_policy_url();
        if ($url) {
            echo ' <a href="' . esc_url($url) . '">' . esc_html__('Privacy policy', 'advance-fake-order-protector') . '</a>';
        }
        echo '</label></p>';
    }

    public function privacy_policy() {
        if (!function_exists('wp_add_privacy_policy_content')) { return; }
        wp_add_privacy_policy_content('Sarkar IT Fake Order Protector', wp_kses_post(
            '<p>Suggested text: We check phone numbers and IP addresses against our local order protection lists. We keep a separate order protection history for up to two days. If you consent to checkout recovery, we save your contact details, address, IP address and cart before an order is placed. Checkout recovery records are removed after our configured retention period (default 30 days). We retain blocklist entries until manually removed for fraud prevention. If enabled by the store, administrators can send your phone number to configured courier services (BDCourier, Steadfast or Pathao) to retrieve delivery history. Courier responses are cached locally for the configured duration. Opening a courier report can query every configured provider; cached responses are reused until expired or explicitly refreshed. Local credential checks send no data. Providers receive the store server IP as part of the connection. Include the enabled providers and their policies in your privacy notice. WhatsApp links open an external service only when clicked. Adapt this text to the services you enable and include their terms and privacy policies.</p>'
        ));
    }

    public function cleanup() {
        global $wpdb;
        $days = max(1, min(365, (int) get_option('afop_incomplete_retention_days', 30)));
        $hours = max(1, min(168, (int) get_option('afop_courier_cache_hours', 24)));
        $lead_cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - $days * DAY_IN_SECONDS);
        $history_cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - 2 * DAY_IN_SECONDS);
        $cache_cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - $hours * HOUR_IN_SECONDS);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export, erasure and retention require current plugin-owned records; cache must not retain deleted personal data.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE updated_at < %s", $wpdb->prefix . 'afop_incomplete_orders', $lead_cutoff));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export, erasure and retention require current plugin-owned records; cache must not retain deleted personal data.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE created_at < %s", $wpdb->prefix . 'afop_order_history', $history_cutoff));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export, erasure and retention require current plugin-owned records; cache must not retain deleted personal data.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE updated_at < %s", $wpdb->prefix . 'afop_courier_cache', $cache_cutoff));
    }

    public function exporters($items) {
        $items['afop-checkout-recovery'] = array('exporter_friendly_name' => __('Checkout recovery records', 'advance-fake-order-protector'), 'callback' => array($this, 'export_data'));
        return $items;
    }

    public function erasers($items) {
        $items['afop-checkout-recovery'] = array('eraser_friendly_name' => __('Checkout recovery records', 'advance-fake-order-protector'), 'callback' => array($this, 'erase_data'));
        return $items;
    }

    public function export_data($email_address, $page = 1) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export, erasure and retention require current plugin-owned records; cache must not retain deleted personal data.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE email = %s ORDER BY id LIMIT 100 OFFSET %d", $wpdb->prefix . 'afop_incomplete_orders', sanitize_email($email_address), (max(1, (int) $page) - 1) * 100), ARRAY_A);
        $data = array();
        foreach ($rows as $row) {
            $values = array();
            foreach (array('phone', 'name', 'email', 'address', 'city', 'cart_data', 'cart_total', 'ip_address', 'status', 'order_id', 'created_at', 'updated_at') as $field) {
                $values[] = array('name' => $field, 'value' => (string) $row[$field]);
            }
            $data[] = array('group_id' => 'afop-checkout-recovery', 'group_label' => __('Checkout recovery records', 'advance-fake-order-protector'), 'item_id' => 'afop-lead-' . $row['id'], 'data' => $values);
        }
        return array('data' => $data, 'done' => count($rows) < 100);
    }

    public function erase_data($email_address, $page = 1) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export, erasure and retention require current plugin-owned records; cache must not retain deleted personal data.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE email = %s ORDER BY id LIMIT 100", $wpdb->prefix . 'afop_incomplete_orders', sanitize_email($email_address)));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export, erasure and retention require current plugin-owned records; cache must not retain deleted personal data.
        foreach ($ids as $id) { $wpdb->delete($wpdb->prefix . 'afop_incomplete_orders', array('id' => (int) $id), array('%d')); }
        return array('items_removed' => !empty($ids), 'items_retained' => false, 'messages' => array(), 'done' => count($ids) < 100);
    }
}
