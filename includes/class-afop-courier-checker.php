<?php
/**
 * Courier Delivery Ratio & Fraud Checker Engine
 * Supports optional BDCourier, Steadfast and Pathao API integrations.
 * Calculates Delivery vs Return ratio, Pathao Rating evaluations, Risk Score, and Multi-Courier breakdowns.
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
     * Check if a specific courier API provider is configured with valid credentials
     *
     * @param string $provider
     * @return bool
     */
    public static function is_provider_configured($provider) {
        if (get_option('afop_courier_consent', 'no') !== 'yes') { return false; }
        if ($provider === 'steadfast') {
            return !empty(trim(get_option('afop_steadfast_api_key', ''))) && !empty(trim(get_option('afop_steadfast_secret_key', '')));

        } elseif ($provider === 'pathao') {
            return !empty(trim(get_option('afop_pathao_client_id', ''))) && !empty(trim(get_option('afop_pathao_client_secret', '')));
        } elseif ($provider === 'bdcourier') {
            return !empty(trim(get_option('afop_bdcourier_api_key', '')));
        }
        return false;
    }

    /**
     * Check if any courier API provider is configured
     *
     * @return bool
     */
    public static function has_any_api_configured() {
        return self::is_provider_configured('bdcourier') ||
               self::is_provider_configured('steadfast') ||
               self::is_provider_configured('pathao');
    }

    /**
     * Get quick/cached courier stats for WooCommerce orders table column rendering (non-blocking)
     *
     * @param string $phone
     * @return array|null
     */
    public static function get_quick_courier_stat($phone) {
        $normalized_phone = AFOP_Validator::normalize_phone($phone);

        if (empty($normalized_phone)) {
            return null;
        }

        $provider = get_option('afop_courier_provider', 'bdcourier');

        if (!in_array($provider, array('bdcourier', 'steadfast', 'pathao'), true)) { return null; }

        // Check local DB cache first (ultra-fast indexed query)
        $cached = self::get_cached_stats($normalized_phone, $provider);
        if ($cached) {
            $cached['is_blocked'] = AFOP_Blocklist::is_blocked('phone', $normalized_phone);
            $cached['has_api'] = true;
            return $cached;
        }

        // Check if API key is configured for the active provider
        $has_key = self::is_provider_configured($provider);

        // If no API key configured, return no_api notice response instead of fake demo stats
        if (!$has_key) {
            return array(
                'success'    => false,
                'no_api'     => true,
                'has_api'    => false,
                'provider'   => $provider,
                'message'    => __('আপনি কোনো API অ্যাড করেন নাই। Courier Ratio দেখতে সেটিংস থেকে API Key যুক্ত করুন।', 'advance-fake-order-protector'),
                'is_blocked' => AFOP_Blocklist::is_blocked('phone', $normalized_phone)
            );
        }

        return null;
    }

    /**
     * Fetch courier stats for a given phone number
     *
     * @param string $phone
     * @param bool $force_refresh
     * @param string $provider
     * @return array
     */
    public static function get_delivery_stats($phone, $force_refresh = false, $provider = '') {
        $normalized_phone = AFOP_Validator::normalize_phone($phone);

        if (empty($normalized_phone)) {
            return array(
                'success' => false,
                'message' => __('Invalid phone number provided.', 'advance-fake-order-protector')
            );
        }

        if (empty($provider)) {
            $provider = get_option('afop_courier_provider', 'bdcourier');
        }

        if (!in_array($provider, array('bdcourier', 'steadfast', 'pathao'), true)) {
            return array('success' => false, 'no_api' => true, 'has_api' => false, 'message' => __('Choose a supported courier provider in settings.', 'advance-fake-order-protector'));
        }

        // Check if API key is configured for this provider
        if (!self::is_provider_configured($provider)) {
            return array(
                'success'    => false,
                'no_api'     => true,
                'has_api'    => false,
                'phone'      => $normalized_phone,
                'provider'   => $provider,
                /* translators: Numeric placeholders are counts; string placeholders identify the provider, customer or blocklist item. */
                'message'    => sprintf(__('আপনি %s API অ্যাড করেন নাই। Courier Ratio দেখতে সেটিংস থেকে API Key যুক্ত করুন।', 'advance-fake-order-protector'), ucfirst($provider)),
                'is_blocked' => AFOP_Blocklist::is_blocked('phone', $normalized_phone),
                'from_cache' => false
            );
        }

        // Check local cache if not force refresh
        if (!$force_refresh) {
            $cached = self::get_cached_stats($normalized_phone, $provider);
            if ($cached) {
                $cached['is_blocked'] = AFOP_Blocklist::is_blocked('phone', $normalized_phone);
                $cached['from_cache'] = true;
                $cached['has_api']    = true;
                $cached['no_api']     = false;
                return $cached;
            }
        }

        // Fetch live stats from chosen API provider
        $stats = null;
        if ($provider === 'steadfast') {
            $stats = self::fetch_steadfast_stats($normalized_phone);

        } elseif ($provider === 'pathao') {
            $stats = self::fetch_pathao_stats($normalized_phone);
        } else {
            $stats = self::fetch_bdcourier_stats($normalized_phone);
        }

        if (!$stats || empty($stats['success'])) {
            if (!isset($stats['message'])) {
                $stats = array('success' => false, 'message' => __('API Data Unavailable', 'advance-fake-order-protector'));
            }
            $stats['has_api'] = true;
            $stats['no_api']  = false;
        } else {
            $stats['has_api'] = true;
            $stats['no_api']  = false;
            // Cache the live successful response
            self::save_to_cache($normalized_phone, $provider, $stats);
        }

        $stats['is_blocked'] = AFOP_Blocklist::is_blocked('phone', $normalized_phone);
        $stats['from_cache'] = false;

        return $stats;
    }

    /**
     * Issue or retrieve cached Pathao OAuth Access Token
     * Uses OAuth 2.0 grant_type password / refresh_token as documented in Pathao Courier Merchant API
     */
    public static function get_pathao_access_token() {
        if (get_option('afop_courier_consent', 'no') !== 'yes') { return new WP_Error('no_consent', 'External courier requests are disabled.'); }
        $client_id     = trim(get_option('afop_pathao_client_id', ''));
        $client_secret = trim(get_option('afop_pathao_client_secret', ''));
        $username      = trim(get_option('afop_pathao_username', ''));
        $password      = trim(get_option('afop_pathao_password', ''));
        $base_url      = rtrim(trim(get_option('afop_pathao_base_url', 'https://courier-api-sandbox.pathao.com')), '/');
        if (!in_array($base_url, array('https://courier-api-sandbox.pathao.com', 'https://api-hermes.pathao.com'), true)) { return new WP_Error('invalid_host', 'Invalid Pathao API host.'); }

        if (empty($client_id) || empty($client_secret) || empty($username) || empty($password)) {
            return new WP_Error('missing_credentials', __('Pathao API Credentials missing. Please enter Client ID, Secret, Username and Password in Settings.', 'advance-fake-order-protector'));
        }

        // Check stored token in option
        $token_data = get_option('afop_pathao_token_data', array());
        $now = time();

        // If access token is valid (with 5-minute safety buffer), return it
        if (!empty($token_data['access_token']) && !empty($token_data['expires_at']) && ($token_data['expires_at'] - 300) > $now) {
            return $token_data['access_token'];
        }

        $endpoint = $base_url . '/aladdin/api/v1/issue-token';

        // Try refresh token if available
        if (!empty($token_data['refresh_token'])) {
            $refresh_payload = array(
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'grant_type'    => 'refresh_token',
                'refresh_token' => $token_data['refresh_token']
            );

            $response = wp_safe_remote_post($endpoint, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json'
                ),
                'body'    => wp_json_encode($refresh_payload),
                'timeout' => 15,
                'redirection' => 0
            ));

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body['access_token'])) {
                    $expires_in = isset($body['expires_in']) ? intval($body['expires_in']) : 432000;
                    $token_info = array(
                        'access_token'  => $body['access_token'],
                        'refresh_token' => isset($body['refresh_token']) ? $body['refresh_token'] : $token_data['refresh_token'],
                        'expires_at'    => time() + $expires_in
                    );
                    update_option('afop_pathao_token_data', $token_info);
                    return $body['access_token'];
                }
            }
        }

        // Fallback to issuing new token using grant_type password
        $payload = array(
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'grant_type'    => 'password',
            'username'      => $username,
            'password'      => $password
        );

        $response = wp_safe_remote_post($endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ),
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
                'redirection' => 0
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['access_token'])) {
            $err_msg = isset($body['message']) ? $body['message'] : (isset($body['error']) ? $body['error'] : __('Failed to issue Pathao access token.', 'advance-fake-order-protector'));
            return new WP_Error('pathao_auth_error', 'Pathao Auth Error: ' . $err_msg);
        }

        $expires_in = isset($body['expires_in']) ? intval($body['expires_in']) : 432000;
        $token_info = array(
            'access_token'  => $body['access_token'],
            'refresh_token' => isset($body['refresh_token']) ? $body['refresh_token'] : '',
            'expires_at'    => time() + $expires_in
        );

        update_option('afop_pathao_token_data', $token_info);
        return $body['access_token'];
    }

    /**
     * Fetch customer fraud & delivery ratio stats from Pathao Courier API
     */
    private static function fetch_pathao_stats($phone) {
        $token = self::get_pathao_access_token();

        if (is_wp_error($token)) {
            return array(
                'success' => false,
                'message' => $token->get_error_message()
            );
        }

        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
        if (substr($clean_phone, 0, 2) === '88' && strlen($clean_phone) === 13) {
            $clean_phone = substr($clean_phone, 2);
        }

        $base_url = rtrim(trim(get_option('afop_pathao_base_url', 'https://courier-api-sandbox.pathao.com')), '/');
        $endpoint = $base_url . '/aladdin/api/v1/user/success-rate';

        $response = wp_safe_remote_post($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            ),
            'body'    => wp_json_encode(array('phone' => $clean_phone)),
            'timeout' => 15,
                'redirection' => 0
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Pathao API Error: ' . $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 401) {
            delete_option('afop_pathao_token_data');
            return array(
                'success' => false,
                'message' => __('Pathao token expired. Please try again.', 'advance-fake-order-protector')
            );
        }

        if ($code !== 200 || !is_array($body)) {
            return array('success' => false, 'message' => __('Pathao returned an invalid response. Please check your account and try again.', 'advance-fake-order-protector'));
        }
        return self::format_pathao_response($clean_phone, $body);
    }

    /**
     * Standardize Pathao API response into unified schema
     */
    private static function format_pathao_response($phone, $raw) {
        $total_orders = 0;
        $delivered    = 0;
        $returned     = 0;
        $risk_level   = 'safe';
        $couriers     = array();

        if (!empty($raw['data'])) {
            $data = $raw['data'];
            $total_orders = isset($data['total_orders']) ? intval($data['total_orders']) : (isset($data['total_parcel']) ? intval($data['total_parcel']) : 0);
            $delivered    = isset($data['delivered_orders']) ? intval($data['delivered_orders']) : (isset($data['delivered']) ? intval($data['delivered']) : (isset($data['success_parcel']) ? intval($data['success_parcel']) : 0));
            $returned     = isset($data['returned_orders']) ? intval($data['returned_orders']) : (isset($data['returned']) ? intval($data['returned']) : (isset($data['cancelled_parcel']) ? intval($data['cancelled_parcel']) : 0));
        }

        if ($total_orders == 0 && ($delivered > 0 || $returned > 0)) {
            $total_orders = $delivered + $returned;
        }

        $delivery_rate = ($total_orders > 0) ? round(($delivered / $total_orders) * 100, 1) : 0;
        $return_rate   = ($total_orders > 0) ? round(($returned / $total_orders) * 100, 1) : 0;

        if ($total_orders > 0) {
            if ($return_rate >= 30 || $delivery_rate < 65) {
                $risk_level = 'high';
            } elseif ($return_rate >= 15 || $delivery_rate < 80) {
                $risk_level = 'medium';
            }
        }

        $couriers[] = array(
            'name'      => 'Pathao Courier',
            'data_type' => 'delivery',
            'total'     => $total_orders,
            'delivered' => $delivered,
            'returned'  => $returned
        );

        return array(
            'success'        => true,
            'phone'          => $phone,
            'provider'       => 'Pathao Courier API',
            'provider_key'   => 'pathao',
            'total_orders'   => $total_orders,
            'delivered'      => $delivered,
            'returned'       => $returned,
            'cancelled'      => $returned,
            'delivery_rate'  => $delivery_rate,
            'return_rate'    => $return_rate,
            'risk_level'     => $risk_level,
            'couriers'       => $couriers,
            'raw_data'       => $raw
        );
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
        
        $response = wp_safe_remote_post($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            ),
            'body'    => wp_json_encode(array('phone' => $phone)),
            'timeout' => 15,
                'redirection' => 0
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

        $response = wp_safe_remote_get($endpoint, array(
            'headers' => array(
                'Api-Key'     => $api_key,
                'Secret-Key'  => $secret_key,
                'Content-Type'=> 'application/json'
            ),
            'timeout' => 15,
                'redirection' => 0
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
     * Standardize legacy courier API responses into unified schema
     */
    private static function format_courier_response($phone, $provider_name, $raw) {
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
                        'data_type' => 'delivery',
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
        $risk_level = 'safe';
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
            'provider_key'   => strtolower(str_replace(' ', '', $provider_name)),
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
     * Save a successful provider response to the local courier cache.
     */
    private static function save_to_cache($phone, $provider, $stats) {
        global $wpdb;
        $table = $wpdb->prefix . 'afop_courier_cache';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This updates the plugin-owned courier cache; no read cache should suppress writes.
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
                'cancelled_count' => isset($stats['cancelled']) ? $stats['cancelled'] : 0,
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
        $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - ($hours * 3600));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This is the plugin-owned courier cache itself; writes update that cache and reads enforce its expiry.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT raw_response FROM %i WHERE phone = %s AND provider = %s AND updated_at >= %s LIMIT 1", $table,
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
        $provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '';

        if (empty($phone)) {
            wp_send_json_error(array('message' => 'Phone number is required.'));
        }

        if ($provider === 'all' || empty($provider)) {
            // Return all 3 providers for multi-tab rendering
            $providers_data = array(
                'bdcourier' => self::get_delivery_stats($phone, $force, 'bdcourier'),
                'steadfast' => self::get_delivery_stats($phone, $force, 'steadfast'),
                'pathao'    => self::get_delivery_stats($phone, $force, 'pathao')
            );

            wp_send_json_success(array(
                'multi_provider'    => true,
                'phone'             => AFOP_Validator::normalize_phone($phone),
                'is_blocked'        => AFOP_Blocklist::is_blocked('phone', $phone),
                'providers'         => $providers_data,
                'active_provider'   => in_array(get_option('afop_courier_provider', 'bdcourier'), array('bdcourier', 'steadfast', 'pathao'), true) ? get_option('afop_courier_provider', 'bdcourier') : 'bdcourier',
                'has_any_api'       => self::has_any_api_configured(),
                'configured_status' => array(
                    'bdcourier' => self::is_provider_configured('bdcourier'),
                    'steadfast' => self::is_provider_configured('steadfast'),
                    'pathao'    => self::is_provider_configured('pathao')
                )
            ));
        } else {
            $stats = self::get_delivery_stats($phone, $force, $provider);
            if (!$stats) {
                wp_send_json_error(array('message' => 'Failed to fetch stats.'));
            }
            wp_send_json_success($stats);
        }
    }

    /**
     * Admin AJAX to check saved credential fields locally, without contacting a provider.
     */
    public function ajax_test_courier_api() {
        check_ajax_referer('afop_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized.'));
        }

        if (get_option('afop_courier_consent', 'no') !== 'yes') {
            wp_send_json_error(array('message' => 'Enable external courier requests in settings first.'));
        }
        $active_provider = get_option('afop_courier_provider', 'bdcourier');
        if (!self::is_provider_configured($active_provider)) {
            wp_send_json_error(array('message' => __('Select a supported provider and save all required credentials first.', 'advance-fake-order-protector')));
        }
        if ($active_provider === 'pathao' && (empty(trim(get_option('afop_pathao_username', ''))) || empty(trim(get_option('afop_pathao_password', ''))))) {
            wp_send_json_error(array('message' => __('Save the Pathao merchant username and password first.', 'advance-fake-order-protector')));
        }
        wp_send_json_success(array('message' => __('Required credentials are saved. No external request was sent and the credentials have not been authenticated. Use an authorized order lookup to verify live access.', 'advance-fake-order-protector')));
    }
}
