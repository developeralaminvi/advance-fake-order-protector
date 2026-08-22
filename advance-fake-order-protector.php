<?php
/**
 * Plugin Name: SARKAR IT Fake protection
 * Plugin URI: https://www.sarkarit.com/
 * Description: Advanced WooCommerce fake order prevention, Bangladeshi phone number validation, IP/Phone 1-click blocklist, repeat order protection with WhatsApp redirection, real-time incomplete orders capture, and multi-courier delivery ratio analytics.
 * Version: 1.4.0
 * Author: SARKAR IT
 * Author URI: https://www.sarkarit.com/
 * Text Domain: advance-fake-order-protector
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * 
 * Copyright: © 2026 SARKAR IT (https://www.sarkarit.com/). All Rights Reserved.
 * Flat No: 4A, House, 9 Main Rd, Dhaka 1207
 * Call for Facebook: +88 01785552264 | Call for Web: +88 01789363695 | Email: info@sarkarit.com
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define Plugin Constants
define('AFOP_VERSION', '1.4.0');
define('AFOP_PLUGIN_FILE', __FILE__);
define('AFOP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AFOP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AFOP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 */
final class Advance_Fake_Order_Protector {

    /**
     * Single instance of the plugin
     * @var Advance_Fake_Order_Protector
     */
    private static $instance = null;

    /**
     * Module instances
     */
    public $validator;
    public $blocklist;
    public $repeat_order;
    public $incomplete_orders;
    public $courier_checker;
    public $admin;

    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required include files
     */
    private function load_dependencies() {
        require_once AFOP_PLUGIN_DIR . 'includes/class-afop-activator.php';
        require_once AFOP_PLUGIN_DIR . 'includes/class-afop-validator.php';
        require_once AFOP_PLUGIN_DIR . 'includes/class-afop-blocklist.php';
        require_once AFOP_PLUGIN_DIR . 'includes/class-afop-repeat-order.php';
        require_once AFOP_PLUGIN_DIR . 'includes/class-afop-incomplete-orders.php';
        require_once AFOP_PLUGIN_DIR . 'includes/class-afop-courier-checker.php';
        require_once AFOP_PLUGIN_DIR . 'includes/class-afop-admin.php';

        // Initialize modules
        $this->validator         = new AFOP_Validator();
        $this->blocklist         = new AFOP_Blocklist();
        $this->repeat_order      = new AFOP_Repeat_Order();
        $this->incomplete_orders = new AFOP_Incomplete_Orders();
        $this->courier_checker   = new AFOP_Courier_Checker();
        $this->admin             = new AFOP_Admin();
    }

    /**
     * Register global hooks
     */
    private function init_hooks() {
        register_activation_hook(AFOP_PLUGIN_FILE, array('AFOP_Activator', 'activate'));
        register_deactivation_hook(AFOP_PLUGIN_FILE, array('AFOP_Activator', 'deactivate'));

        // Declare WooCommerce Feature Compatibility (HPOS & Blocks)
        add_action('before_woocommerce_init', array($this, 'declare_woocommerce_compatibility'));

        add_action('plugins_loaded', array($this, 'check_woocommerce'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('wp_footer', array($this, 'render_frontend_modals'));
    }

    /**
     * Declare HPOS & Cart/Checkout Blocks compatibility
     */
    public function declare_woocommerce_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', AFOP_PLUGIN_FILE, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', AFOP_PLUGIN_FILE, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('order_attribution', AFOP_PLUGIN_FILE, true);
        }
    }

    /**
     * Check if WooCommerce is active
     */
    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p><strong>' . esc_html__('Advance Fake Order Protector', 'advance-fake-order-protector') . '</strong> ' . esc_html__('requires WooCommerce to be installed and active.', 'advance-fake-order-protector') . '</p></div>';
            });
        }
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_frontend_assets() {
        if (!is_checkout() && !is_cart() && !apply_filters('afop_always_enqueue_frontend', false)) {
            return;
        }

        wp_enqueue_style(
            'afop-fontawesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
            array(),
            '6.5.1'
        );

        wp_enqueue_style(
            'afop-frontend-css',
            AFOP_PLUGIN_URL . 'assets/css/afop-frontend.css',
            array('afop-fontawesome'),
            AFOP_VERSION
        );

        wp_enqueue_script(
            'afop-frontend-js',
            AFOP_PLUGIN_URL . 'assets/js/afop-frontend.js',
            array('jquery'),
            AFOP_VERSION,
            true
        );

        $whatsapp_num = get_option('afop_whatsapp_number', '');
        $whatsapp_msg = get_option('afop_whatsapp_message', 'Hello Support, my order checkout is blocked. Please help me.');

        // Format whatsapp clean number
        $clean_wa = preg_replace('/[^0-9]/', '', $whatsapp_num);
        if (!empty($clean_wa) && substr($clean_wa, 0, 2) !== '88' && strlen($clean_wa) === 11 && substr($clean_wa, 0, 2) === '01') {
            $clean_wa = '88' . $clean_wa;
        }

        $wa_link = !empty($clean_wa) ? 'https://wa.me/' . $clean_wa . '?text=' . rawurlencode($whatsapp_msg) : '#';
        $repeat_wa_msg = get_option('afop_repeat_whatsapp_msg', 'Hello Support, I placed an order recently and would like to order the same product again. Please assist me.');
        $repeat_wa_link = !empty($clean_wa) ? 'https://wa.me/' . $clean_wa . '?text=' . rawurlencode($repeat_wa_msg) : '#';

        wp_localize_script('afop-frontend-js', 'afop_data', array(
            'ajax_url'            => admin_url('admin-ajax.php'),
            'nonce'               => wp_create_nonce('afop_frontend_nonce'),
            'is_checkout'         => is_checkout(),
            'enable_bd_validation'=> get_option('afop_enable_bd_validation', 'yes'),
            'enable_repeat_check' => get_option('afop_enable_repeat_check', 'yes'),
            'repeat_time_limit'   => intval(get_option('afop_repeat_time_limit', 60)), // in minutes
            'whatsapp_number'     => $whatsapp_num,
            'whatsapp_link'       => $wa_link,
            'repeat_whatsapp_link'=> $repeat_wa_link,
            'enable_incomplete'   => get_option('afop_enable_incomplete_capture', 'yes'),
            'i18n'                => array(
                'invalid_phone_title'   => get_option('afop_invalid_phone_title', 'ভুল মোবাইল নম্বর!'),
                'invalid_phone_msg'     => get_option('afop_invalid_phone_msg', 'আপনার নম্বরটি ভুল। দয়া করে সঠিক ১১ ডিজিটের বাংলাদেশী মোবাইল নম্বর লিখুন।'),
                'blocked_title'         => get_option('afop_blocked_title', 'অর্ডার ব্লক করা হয়েছে!'),
                'blocked_msg'           => get_option('afop_blocked_msg', 'দুঃখিত, আপনার আইপি বা মোবাইল নম্বরটি নিরাপত্তা কারণে ব্লক করা আছে। সাহায্যের জন্য আমাদের সাপোর্টে যোগাযোগ করুন।'),
                'repeat_order_title'    => get_option('afop_repeat_order_title', 'পুনরায় অর্ডার সংক্রান্ত তথ্য'),
                'repeat_order_msg'      => get_option('afop_repeat_order_msg', 'আপনি {minutes} মিনিট আগে এই প্রোডাক্টটি অর্ডার করেছিলেন। পুনরায় একই প্রোডাক্ট অর্ডার করতে চাইলে অনুগ্রহ করে আমাদের হোয়াটসঅ্যাপে যোগাযোগ করুন।'),
                'repeat_whatsapp_btn'   => get_option('afop_repeat_whatsapp_btn', 'হোয়াটসঅ্যাপে অর্ডার করুন'),
                'whatsapp_btn'          => get_option('afop_whatsapp_btn_text', 'হোয়াটসঅ্যাপে যোগাযোগ করুন'),
                'close_btn'             => 'বন্ধ করুন',
                'ok_btn'                => 'ঠিক আছে'
            )
        ));
    }

    /**
     * Render frontend modal templates in footer
     */
    public function render_frontend_modals() {
        if (!is_checkout() && !is_cart()) {
            return;
        }
        ?>
        <!-- AFOP Modern Modal Container -->
        <div id="afop-modal-overlay" class="afop-modal-overlay" style="display:none;">
            <div class="afop-modal-card" role="dialog" aria-modal="true">
                <button type="button" class="afop-modal-close" id="afop-modal-close" aria-label="Close">&times;</button>
                <div class="afop-modal-icon-wrapper" id="afop-modal-icon">
                    <!-- Dynamic Icon -->
                </div>
                <h3 class="afop-modal-title" id="afop-modal-title"></h3>
                <p class="afop-modal-desc" id="afop-modal-desc"></p>
                <div class="afop-modal-extra" id="afop-modal-extra"></div>
                <div class="afop-modal-actions" id="afop-modal-actions">
                    <!-- Dynamic Buttons -->
                </div>
            </div>
        </div>
        <?php
    }
}

/**
 * Initialize Plugin
 */
function afop_get_plugin() {
    return Advance_Fake_Order_Protector::get_instance();
}

// Start execution
afop_get_plugin();
