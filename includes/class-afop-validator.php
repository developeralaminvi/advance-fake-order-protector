<?php
/**
 * Bangladeshi Phone Number Validator & Fake Number Protector
 */

if (!defined('ABSPATH')) {
    exit;
}

class AFOP_Validator {

    public function __construct() {
        // Server side WooCommerce Checkout Validation
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_phone'));
        
        // AJAX Live Phone Verification
        add_action('wp_ajax_afop_validate_phone_ajax', array($this, 'ajax_validate_phone'));
        add_action('wp_ajax_nopriv_afop_validate_phone_ajax', array($this, 'ajax_validate_phone'));
    }

    /**
     * Convert Bengali numerals to English numerals
     *
     * @param string $number
     * @return string
     */
    public static function convert_bangla_to_english_digits($number) {
        $bn_digits = array('০','১','২','৩','৪','৫','৬','৭','৮','৯');
        $en_digits = array('0','1','2','3','4','5','6','7','8','9');
        return str_replace($bn_digits, $en_digits, $number);
    }

    /**
     * Normalize phone number to standard 11 digit format (01XXXXXXXXX)
     *
     * @param string $phone
     * @return string
     */
    public static function normalize_phone($phone) {
        if (empty($phone)) {
            return '';
        }

        // Convert bangla numerals
        $phone = self::convert_bangla_to_english_digits($phone);

        // Remove all non-numeric characters except +
        $phone = preg_replace('/[^\d+]/', '', $phone);

        // Remove international code +88 or 88
        if (strpos($phone, '+88') === 0) {
            $phone = substr($phone, 3);
        } elseif (strpos($phone, '88') === 0 && strlen($phone) > 11) {
            $phone = substr($phone, 2);
        }

        // Remove leading 0 if double (e.g., 0017...)
        $phone = ltrim($phone, '0');
        $phone = '0' . $phone;

        return trim($phone);
    }

    /**
     * Check if the phone number is a valid Bangladeshi mobile number
     *
     * @param string $phone
     * @return array array('valid' => bool, 'reason' => string, 'normalized' => string)
     */
    public static function is_valid_bd_phone($phone) {
        $normalized = self::normalize_phone($phone);

        // Check length
        if (strlen($normalized) !== 11) {
            return array(
                'valid'      => false,
                'reason'     => 'নম্বরটি অবশ্যই ১১ ডিজিটের হতে হবে।',
                'normalized' => $normalized
            );
        }

        // Check valid BD operator prefixes (013, 014, 015, 016, 017, 018, 019)
        // 013/017: GP, 014/019: Banglalink, 015: Teletalk, 016/018: Robi/Airtel
        if (!preg_match('/^01[3-9]\d{8}$/', $normalized)) {
            return array(
                'valid'      => false,
                'reason'     => 'অপারেটর প্রিফিক্সটি সঠিক নয় (013-019 হতে হবে)।',
                'normalized' => $normalized
            );
        }

        // Strict fake pattern checking
        if (get_option('afop_strict_fake_patterns', 'yes') === 'yes') {
            $fake_check = self::is_fake_pattern($normalized);
            if ($fake_check['is_fake']) {
                return array(
                    'valid'      => false,
                    'reason'     => $fake_check['reason'],
                    'normalized' => $normalized
                );
            }
        }

        return array(
            'valid'      => true,
            'reason'     => 'Valid Bangladeshi mobile number',
            'normalized' => $normalized
        );
    }

    /**
     * Check for common fake patterns (e.g., all same digits, sequential digits)
     *
     * @param string $phone 11 digit normalized number
     * @return array
     */
    public static function is_fake_pattern($phone) {
        // Extract suffix (last 8 digits after 01X)
        $suffix = substr($phone, 3);

        // 1. All same digits in suffix (e.g., 01700000000, 01711111111)
        if (preg_match('/^(\d)\1{7}$/', $suffix)) {
            return array('is_fake' => true, 'reason' => 'অবাস্তব/ডামি নম্বর শনাক্ত হয়েছে (সব সংখ্যা একই)।');
        }

        // 2. Too many repeating digits (e.g., 6 or more identical digits in suffix)
        if (preg_match('/(\d)\1{5,}/', $suffix)) {
            return array('is_fake' => true, 'reason' => 'অবাস্তব/ডামি নম্বর শনাক্ত হয়েছে (অতিরিক্ত পুনরাবৃত্তি)।');
        }

        // 3. Known test patterns
        $dummy_patterns = array(
            '01234567890',
            '01987654321',
            '01712345678',
            '01812345678',
            '01912345678',
            '01612345678',
            '01512345678',
            '01312345678',
            '01412345678',
            '01700000000',
            '01800000000',
            '01900000000',
            '01600000000',
            '01500000000',
            '01300000000',
            '01400000000',
            '01711111111',
            '01811111111',
            '01911111111',
            '01799999999',
            '01899999999',
            '01999999999',
            '01787654321',
            '01887654321',
            '01987654321'
        );

        if (in_array($phone, $dummy_patterns)) {
            return array('is_fake' => true, 'reason' => 'টেস্ট বা ডামি নম্বর গ্রহণযোগ্য নয়।');
        }

        // 4. Sequential numbers check (e.g. 12345678 or 87654321)
        if ($suffix === '12345678' || $suffix === '87654321' || $suffix === '01234567' || $suffix === '76543210') {
            return array('is_fake' => true, 'reason' => 'ধারাবাহিক ডামি নম্বর গ্রহণযোগ্য নয়।');
        }

        return array('is_fake' => false, 'reason' => '');
    }

    /**
     * AJAX endpoint to validate phone number on input blur/keyup
     */
    public function ajax_validate_phone() {
        check_ajax_referer('afop_frontend_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

        if (empty($phone)) {
            wp_send_json_error(array('message' => 'Phone number is empty.'));
        }

        $validation = self::is_valid_bd_phone($phone);

        if (!$validation['valid']) {
            wp_send_json_error(array(
                'valid'   => false,
                'title'   => get_option('afop_invalid_phone_title', 'ভুল মোবাইল নম্বর!'),
                'message' => get_option('afop_invalid_phone_msg', 'আপনার নম্বরটি ভুল। দয়া করে সঠিক ১১ ডিজিটের বাংলাদেশী মোবাইল নম্বর লিখুন।'),
                'reason'  => $validation['reason']
            ));
        }

        // Also check if this number is blocked
        $is_blocked = AFOP_Blocklist::is_blocked('phone', $validation['normalized']);
        if ($is_blocked) {
            wp_send_json_error(array(
                'valid'      => false,
                'is_blocked' => true,
                'title'      => get_option('afop_blocked_title', 'অর্ডার ব্লক করা হয়েছে!'),
                'message'    => get_option('afop_blocked_msg', 'দুঃখিত, আপনার মোবাইল নম্বরটি ব্লক করা আছে। সাহায্যের জন্য সাপোর্টে যোগাযোগ করুন।')
            ));
        }

        wp_send_json_success(array(
            'valid'      => true,
            'normalized' => $validation['normalized']
        ));
    }

    /**
     * WooCommerce Checkout Process Validation (Server-side defense)
     */
    public function validate_checkout_phone() {
        if (get_option('afop_enable_bd_validation', 'yes') !== 'yes') {
            return;
        }

        $billing_phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';

        if (empty($billing_phone)) {
            wc_add_notice(__('অনুগ্রহ করে আপনার মোবাইল নম্বরটি লিখুন।', 'advance-fake-order-protector'), 'error');
            return;
        }

        $check = self::is_valid_bd_phone($billing_phone);

        if (!$check['valid']) {
            $msg = get_option('afop_invalid_phone_msg', 'আপনার নম্বরটি ভুল। দয়া করে সঠিক ১১ ডিজিটের বাংলাদেশী মোবাইল নম্বর লিখুন।');
            wc_add_notice(esc_html($msg), 'error');
        }
    }
}
