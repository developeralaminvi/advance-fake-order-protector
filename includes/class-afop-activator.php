<?php
/**
 * Plugin Activator Class
 * Handles database table generation and default option setups
 */

if (!defined('ABSPATH')) {
    exit;
}

class AFOP_Activator {

    /**
     * Run on plugin activation
     */
    public static function activate() {
        self::create_tables();
        self::set_default_options();
    }

    /**
     * Run on plugin deactivation
     */
    public static function deactivate() {
        // We do not drop tables on deactivation to preserve merchant data
    }

    /**
     * Create required custom database tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // 1. Blocklist Table
        $table_blocklist = $wpdb->prefix . 'afop_blocklist';
        $sql_blocklist = "CREATE TABLE IF NOT EXISTS {$table_blocklist} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL DEFAULT 'phone', /* 'phone' or 'ip' */
            value varchar(100) NOT NULL,
            reason text NULL,
            blocked_by varchar(100) DEFAULT 'admin',
            status varchar(20) NOT NULL DEFAULT 'blocked',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY type_value (type, value),
            KEY type (type),
            KEY status (status)
        ) {$charset_collate};";
        dbDelta($sql_blocklist);

        // 2. Incomplete Orders Table
        $table_incomplete = $wpdb->prefix . 'afop_incomplete_orders';
        $sql_incomplete = "CREATE TABLE IF NOT EXISTS {$table_incomplete} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_key varchar(64) NOT NULL,
            phone varchar(30) NOT NULL,
            name varchar(150) NULL,
            email varchar(150) NULL,
            address text NULL,
            city varchar(100) NULL,
            cart_data longtext NULL,
            cart_total decimal(10,2) DEFAULT '0.00',
            ip_address varchar(45) NULL,
            status varchar(30) NOT NULL DEFAULT 'incomplete', /* 'incomplete', 'converted', 'recovered', 'trash' */
            order_id bigint(20) NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY phone (phone),
            KEY session_key (session_key),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_incomplete);

        // 3. Order History Log Table (For lightning-fast repeat order checks)
        $table_history = $wpdb->prefix . 'afop_order_history';
        $sql_history = "CREATE TABLE IF NOT EXISTS {$table_history} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            phone varchar(30) NOT NULL,
            ip_address varchar(45) NOT NULL,
            product_ids text NOT NULL, /* comma separated or json */
            order_total decimal(10,2) DEFAULT '0.00',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY phone (phone),
            KEY ip_address (ip_address),
            KEY order_id (order_id),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_history);

        // 4. Courier Ratio Cache Table
        $table_courier = $wpdb->prefix . 'afop_courier_cache';
        $sql_courier = "CREATE TABLE IF NOT EXISTS {$table_courier} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            phone varchar(30) NOT NULL,
            provider varchar(50) NOT NULL DEFAULT 'bdcourier',
            delivery_rate decimal(5,2) DEFAULT '0.00',
            return_rate decimal(5,2) DEFAULT '0.00',
            total_orders int(11) DEFAULT 0,
            delivered_count int(11) DEFAULT 0,
            return_count int(11) DEFAULT 0,
            cancelled_count int(11) DEFAULT 0,
            raw_response longtext NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY phone_provider (phone, provider),
            KEY phone (phone)
        ) {$charset_collate};";
        dbDelta($sql_courier);
    }

    /**
     * Set default plugin options
     */
    public static function set_default_options() {
        $defaults = array(
            'afop_enable_bd_validation'     => 'yes',
            'afop_strict_fake_patterns'     => 'yes',
            'afop_invalid_phone_title'      => 'ভুল মোবাইল নম্বর!',
            'afop_invalid_phone_msg'        => 'আপনার নম্বরটি ভুল। দয়া করে সঠিক ১১ ডিজিটের বাংলাদেশী মোবাইল নম্বর লিখুন।',
            
            'afop_enable_blocklist'         => 'yes',
            'afop_blocked_title'            => 'অর্ডার ব্লক করা হয়েছে!',
            'afop_blocked_msg'              => 'দুঃখিত, আপনার আইপি বা মোবাইল নম্বরটি নিরাপত্তা কারণে ব্লক করা আছে। সাহায্যের জন্য আমাদের সাপোর্টে যোগাযোগ করুন।',
            'afop_whatsapp_number'          => '',
            'afop_whatsapp_message'         => 'Hello Support, I am trying to order but my checkout is showing blocked. Please help me.',
            'afop_whatsapp_btn_text'        => 'হোয়াটসঅ্যাপে যোগাযোগ করুন',

            'afop_enable_repeat_check'      => 'yes',
            'afop_repeat_time_limit'        => 60, // 60 minutes
            'afop_repeat_order_title'       => 'পুনরায় অর্ডার সংক্রান্ত তথ্য',
            'afop_repeat_order_msg'         => 'আপনি {minutes} মিনিট আগে এই প্রোডাক্টটি অর্ডার করেছিলেন। পুনরায় একই প্রোডাক্ট অর্ডার করতে চাইলে অনুগ্রহ করে আমাদের হোয়াটসঅ্যাপে যোগাযোগ করুন।',
            'afop_repeat_whatsapp_btn'      => 'হোয়াটসঅ্যাপে অর্ডার করুন',
            'afop_repeat_whatsapp_msg'      => 'Hello Support, I ordered this product earlier and want to place a repeat order. Please assist me.',

            'afop_enable_incomplete_capture'=> 'yes',
            'afop_incomplete_retention_days'=> 30,

            'afop_courier_provider'         => 'bdcourier', // 'bdcourier', 'steadfast', 'fraudbd'
            'afop_bdcourier_api_key'        => '',
            'afop_steadfast_api_key'        => '',
            'afop_steadfast_secret_key'     => '',
            'afop_fraudbd_api_key'          => '',
            'afop_courier_cache_hours'      => 24
        );

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
}
