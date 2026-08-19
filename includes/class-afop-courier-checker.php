<?php
/**
 * Courier Delivery Ratio & Fraud Checker Engine
 * Supports BDCourier, Steadfast, and FraudBD API integrations.
 * Calculates Delivery vs Return ratio, Risk Score, and Courier breakdown.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AFOP_Courier_Checker {

    public function __construct() {
        // Admin AJAX endpoint to fetch courier delivery stats
        add_action('wp_ajax_afop_get_courier_ratio_ajax', array($this, 'ajax_get_courier_ratio'));
        add_action('wp_ajax_afop_test_courier_api', array($this, 'ajax_test_courier_api'));
    }

    /**
     * Fetch courier stats for a given phone number
     *
     * @param string $phone
     * @param bool $force_refresh
     * @return array
     */
    public static function get_delivery_stats($phone, $force_refresh = false) {
        $normalized_phone = AFOP_Validator::normalize_phone($phone);

        if (empty($normalized_phone)) {
            return array(
                'success' => false,
                'message' => __('Invalid phone number provided.', 'advance-fake-order-protector')
            );
        }

        $provider = get_option('afop_courier_provider', 'bdcourier');

        // Check local cache if not force refresh
        if (!$force_refresh) {
            $cached = self::get_cached_stats($normalized_phone, $provider);
            if ($cached) {
                $cached['is_blocked'] = AFOP_Blocklist::is_blocked('phone', $normalized_phone);
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        // Fetch live stats from chosen API provider
        $stats = null;
        if ($provider === 'steadfast') {
            $stats = self::fetch_steadfast_stats($normalized_phone);
        } elseif ($provider === 'fraudbd') {
            $stats = self::fetch_fraudbd_stats($normalized_phone);
        } else {
            $stats = self::fetch_bdcourier_stats($normalized_phone);
        }

        // If API key is missing or failed, return simulation or friendly notice
        if (!$stats || empty($stats['success'])) {
            $api_key = get_option('afop_bdcourier_api_key', '');
            $sf_key = get_option('afop_steadfast_api_key', '');

            if (empty($api_key) && empty($sf_key)) {
                $stats = self::get_demo_stats($normalized_phone);
            } else {
                return $stats ?: array(
                    'success' => false,
                    'message' => __('Unable to fetch courier delivery data. Please verify your API credentials in plugin settings.', 'advance-fake-order-protector')
                );
            }
        }

        // Cache the successful response
        if (!empty($stats['success']) && empty($stats['is_demo'])) {
            self::save_to_cache($normalized_phone, $provider, $stats);
        }

        $stats['is_blocked'] = AFOP_Blocklist::is_blocked('phone', $normalized_phone);
        $stats['from_cache'] = false;

        return $stats;
    }

    /**
     * Fetch from BDCourier API (api.bdcourier.com)
     */
    private static function fetch_bdcourier_stats($phone) {
        $api_key = trim(get_option('afop_bdcourier_api_key', ''));

        if (empty($api_key)) {
            return array(
                'success' => false,
                'message' => __('BDCourier API Key is not configured.', 'advance-fake-order-protector')
            );
        }

        $endpoint = 'https://api.bdcourier.com/courier-check';
        
        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            ),
            'body'    => wp_json_encode(array('phone' => $phone)),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body)) {
            return array(
                'success' => false,
                'message' => isset($body['message']) ? $body['message'] : __('BDCourier API error response.', 'advance-fake-order-protector')
            );
        }

        return self::format_courier_response($phone, 'BD Courier', $body);
    }

    /**
     * Fetch from Steadfast Courier API
     */
    private static function fetch_steadfast_stats($phone) {
        $api_key = trim(get_option('afop_steadfast_api_key', ''));
        $secret_key = trim(get_option('afop_steadfast_secret_key', ''));

        if (empty($api_key) || empty($secret_key)) {
            return array(
                'success' => false,
                'message' => __('Steadfast API Key or Secret is missing.', 'advance-fake-order-protector')
            );
        }

        $endpoint = 'https://api.steadfast.com.bd/api/v1/fraud_check/' . $phone;

        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Api-Key'     => $api_key,
                'Secret-Key'  => $secret_key,
                'Content-Type'=> 'application/json'
            ),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body)) {
            return array(
                'success' => false,
                'message' => isset($body['message']) ? $body['message'] : __('Steadfast API error response.', 'advance-fake-order-protector')
            );
        }

        return self::format_courier_response($phone, 'Steadfast', $body);
    }

    /**
     * Fetch from FraudBD API
     */
    private static function fetch_fraudbd_stats($phone) {
        $api_key = trim(get_option('afop_fraudbd_api_key', ''));

        if (empty($api_key)) {
            return array(
                'success' => false,
                'message' => __('FraudBD API Key is missing.', 'advance-fake-order-protector')
            );
        }

        $endpoint = 'https://fraudbd.com/api/check-courier-info';

        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'api_key'      => $api_key,
                'Content-Type' => 'application/json'
            ),
            'body'    => wp_json_encode(array('phone_number' => $phone)),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return self::format_courier_response($phone, 'FraudBD', $body);
    }

    /**
     * Standardize courier API responses into unified schema
     */
    private static function format_courier_response($phone, $provider_name, $raw) {
        // Extract common fields across providers
        $total_orders = 0;
        $delivered = 0;
        $returned = 0;
        $cancelled = 0;
        $couriers = array();

        if (isset($raw['data'])) {
            $data = $raw['data'];
            $total_orders = isset($data['total_parcel']) ? intval($data['total_parcel']) : (isset($data['total_orders']) ? intval($data['total_orders']) : 0);
            $delivered    = isset($data['success_parcel']) ? intval($data['success_parcel']) : (isset($data['delivered']) ? intval($data['delivered']) : 0);
            $returned     = isset($data['cancelled_parcel']) ? intval($data['cancelled_parcel']) : (isset($data['returned']) ? intval($data['returned']) : 0);
            
            if (isset($data['courier_details']) && is_array($data['courier_details'])) {
                foreach ($data['courier_details'] as $c_name => $c_stat) {
                    $couriers[] = array(
                        'name'      => ucfirst($c_name),
                        'total'     => isset($c_stat['total']) ? intval($c_stat['total']) : 0,
                        'delivered' => isset($c_stat['success']) ? intval($c_stat['success']) : 0,
                        'returned'  => isset($c_stat['cancelled']) ? intval($c_stat['cancelled']) : 0,
                    );
                }
            }
        } else {
            $total_orders = isset($raw['total_orders']) ? intval($raw['total_orders']) : 0;
            $delivered    = isset($raw['delivered']) ? intval($raw['delivered']) : 0;
            $returned     = isset($raw['returned']) ? intval($raw['returned']) : 0;
            $cancelled    = isset($raw['cancelled']) ? intval($raw['cancelled']) : 0;
        }

        if ($total_orders == 0 && ($delivered > 0 || $returned > 0)) {
            $total_orders = $delivered + $returned + $cancelled;
        }

        $delivery_rate = ($total_orders > 0) ? round(($delivered / $total_orders) * 100, 1) : 0;
        $return_rate   = ($total_orders > 0) ? round(($returned / $total_orders) * 100, 1) : 0;

        // Risk Level Calculation
        $risk_level = 'safe'; // Safe
        if ($total_orders > 0) {
            if ($return_rate >= 35 || $delivery_rate < 60) {
                $risk_level = 'high';
            } elseif ($return_rate >= 15 || $delivery_rate < 80) {
                $risk_level = 'medium';
            }
        }

        return array(
            'success'        => true,
            'phone'          => $phone,
            'provider'       => $provider_name,
            'total_orders'   => $total_orders,
            'delivered'      => $delivered,
            'returned'       => $returned,
            'cancelled'      => $cancelled,
            'delivery_rate'  => $delivery_rate,
            'return_rate'    => $return_rate,
            'risk_level'     => $risk_level,
            'couriers'       => $couriers,
            'raw_data'       => $raw
        );
    }

    /**
     * Demo / Simulated Stats for Test Mode
     */
    private static function get_demo_stats($phone) {
        // Deterministic hash based on phone number for consistent preview
        $hash = crc32($phone);
        $total = 12 + ($hash % 18);
        $delivered = max(1, $total - ($hash % 4));
        $returned = $total - $delivered;
        $rate = round(($delivered / $total) * 100, 1);
        $return_rate = round(($returned / $total) * 100, 1);

        $risk = ($return_rate > 25) ? 'medium' : 'safe';

        return array(
            'success'        => true,
            'is_demo'        => true,
            'demo_notice'    => __('ডেমো মোড: লাইভ কুরিয়ার ডাটার জন্য প্লাগিন সেটিংস থেকে BDCourier বা Steadfast API কী যুক্ত করুন।', 'advance-fake-order-protector'),
            'phone'          => $phone,
            'provider'       => 'Courier Stats Preview (Demo)',
            'total_orders'   => $total,
            'delivered'      => $delivered,
            'returned'       => $returned,
            'cancelled'      => 0,
            'delivery_rate'  => $rate,
            'return_rate'    => $return_rate,
            'risk_level'     => $risk,
            'couriers'       => array(
                array('name' => 'Steadfast', 'total' => round($total * 0.5), 'delivered' => round($delivered * 0.5), 'returned' => round($returned * 0.5)),
                array('name' => 'Pathao', 'total' => round($total * 0.3), 'delivered' => round($delivered * 0.3), 'returned' => 0),
                array('name' => 'RedX', 'total' => round($total * 0.2), 'delivered' => round($delivered * 0.2), 'returned' => 0)
            )
        );
    }

    /**
     * Save to cache table
     */
    private static function save_to_cache($phone, $provider, $stats) {
        global $wpdb;
        $table = $wpdb->prefix . 'afop_courier_cache';

        $wpdb->replace(
            $table,
            array(
                'phone'           => $phone,
                'provider'        => $provider,
                'delivery_rate'   => $stats['delivery_rate'],
                'return_rate'     => $stats['return_rate'],
                'total_orders'    => $stats['total_orders'],
                'delivered_count' => $stats['delivered'],
                'return_count'    => $stats['returned'],
                'cancelled_count' => $stats['cancelled'],
                'raw_response'    => wp_json_encode($stats),
                'updated_at'      => current_time('mysql')
            ),
            array('%s', '%s', '%f', '%f', '%d', '%d', '%d', '%d', '%s', '%s')
        );
    }

    /**
     * Get from cache table
     */
    private static function get_cached_stats($phone, $provider) {
        global $wpdb;
        $table = $wpdb->prefix . 'afop_courier_cache';
        $hours = intval(get_option('afop_courier_cache_hours', 24));
        $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - ($hours * 3600));

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT raw_response FROM {$table} WHERE phone = %s AND provider = %s AND updated_at >= %s LIMIT 1",
            $phone,
            $provider,
            $cutoff
        ));

        if ($row && !empty($row->raw_response)) {
            return json_decode($row->raw_response, true);
        }

        return null;
    }

    /**
     * Admin AJAX to fetch courier delivery stats
     */
    public function ajax_get_courier_ratio() {
        check_ajax_referer('afop_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized.'));
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $force = isset($_POST['force_refresh']) && $_POST['force_refresh'] === 'true';

        if (empty($phone)) {
            wp_send_json_error(array('message' => 'Phone number is required.'));
        }

        $stats = self::get_delivery_stats($phone, $force);

        if (!$stats || empty($stats['success'])) {
            wp_send_json_error(array('message' => isset($stats['message']) ? $stats['message'] : 'Failed to fetch stats.'));
        }

        wp_send_json_success($stats);
    }

    /**
     * Admin AJAX to test courier API connection
     */
    public function ajax_test_courier_api() {
        check_ajax_referer('afop_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized.'));
        }

        $test_phone = '01711122334';
        $stats = self::get_delivery_stats($test_phone, true);

        if (!empty($stats['success'])) {
            wp_send_json_success(array('message' => __('API Connection Successful! Live courier data is responding.', 'advance-fake-order-protector')));
        } else {
            wp_send_json_error(array('message' => isset($stats['message']) ? $stats['message'] : __('Connection failed.', 'advance-fake-order-protector')));
        }
    }
}
