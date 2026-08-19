<?php
/**
 * Admin Panel, Settings, Orders List Custom Columns & Actions
 */

if (!defined('ABSPATH')) {
    exit;
}

class AFOP_Admin {

    public function __construct() {
        // Admin Menu
        add_action('admin_menu', array($this, 'register_admin_menus'));

        // Admin Assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // WooCommerce Orders Table Columns (Classic CPT)
        add_filter('manage_edit-shop_order_columns', array($this, 'add_orders_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_orders_column_content'), 10, 2);

        // WooCommerce HPOS (High-Performance Order Storage) Columns
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_orders_column'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_hpos_orders_column_content'), 10, 2);

        // Single Order Edit Meta Box
        add_action('add_meta_boxes', array($this, 'register_order_meta_box'));

        // Admin Footer Courier Modal
        add_action('admin_footer', array($this, 'render_admin_courier_modal'));

        // Save Settings Handler
        add_action('admin_init', array($this, 'handle_save_settings'));
    }

    /**
     * Register Admin Menus
     */
    public function register_admin_menus() {
        // Incomplete orders count for badge
        $incomplete_count = $this->get_incomplete_count();
        $badge = $incomplete_count > 0 ? sprintf(' <span class="update-plugins count-%d"><span class="plugin-count">%d</span></span>', $incomplete_count, $incomplete_count) : '';

        // 1. Submenu directly under WooCommerce menu (right below Orders)
        add_submenu_page(
            'woocommerce',
            __('Incomplete Orders', 'advance-fake-order-protector'),
            __('Incomplete Orders', 'advance-fake-order-protector') . $badge,
            'manage_woocommerce',
            'afop-incomplete-orders',
            array($this, 'render_incomplete_orders_page')
        );

        // 2. Main Plugin Top-Level Menu
        add_menu_page(
            __('Fake Order Protector', 'advance-fake-order-protector'),
            __('Fake Order Protector', 'advance-fake-order-protector'),
            'manage_woocommerce',
            'afop-settings',
            array($this, 'render_settings_page'),
            'dashicons-shield-alt',
            56
        );

        add_submenu_page(
            'afop-settings',
            __('Settings & Dashboard', 'advance-fake-order-protector'),
            __('Settings', 'advance-fake-order-protector'),
            'manage_woocommerce',
            'afop-settings',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'afop-settings',
            __('Incomplete Orders', 'advance-fake-order-protector'),
            __('Incomplete Orders', 'advance-fake-order-protector') . $badge,
            'manage_woocommerce',
            'afop-incomplete-orders',
            array($this, 'render_incomplete_orders_page')
        );

        add_submenu_page(
            'afop-settings',
            __('Blocklist Manager', 'advance-fake-order-protector'),
            __('Blocklist Manager', 'advance-fake-order-protector'),
            'manage_woocommerce',
            'afop-blocklist',
            array($this, 'render_blocklist_page')
        );
    }

    /**
     * Get pending incomplete orders count
     */
    private function get_incomplete_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'afop_incomplete_orders';
        $count = $wpdb->get_var("SELECT COUNT(id) FROM {$table} WHERE status = 'incomplete'");
        return intval($count);
    }

    /**
     * Enqueue Admin Scripts and Styles
     */
    public function enqueue_admin_assets($hook) {
        $screen = get_current_screen();
        $is_order_screen = $screen && (
            $screen->id === 'edit-shop_order' || 
            $screen->id === 'shop_order' || 
            $screen->id === 'woocommerce_page_wc-orders' ||
            strpos($screen->id, 'afop') !== false
        );

        if (!$is_order_screen) {
            return;
        }

        // FontAwesome 6
        wp_enqueue_style(
            'afop-fontawesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
            array(),
            '6.5.1'
        );

        wp_enqueue_style(
            'afop-admin-css',
            AFOP_PLUGIN_URL . 'assets/css/afop-admin.css',
            array('afop-fontawesome'),
            AFOP_VERSION
        );

        wp_enqueue_script(
            'afop-admin-js',
            AFOP_PLUGIN_URL . 'assets/js/afop-admin.js',
            array('jquery'),
            AFOP_VERSION,
            true
        );

        wp_localize_script('afop-admin-js', 'afop_admin_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('afop_admin_nonce'),
            'i18n'     => array(
                'loading'          => __('তথ্য লোড হচ্ছে...', 'advance-fake-order-protector'),
                'block'            => __('Block', 'advance-fake-order-protector'),
                'unblock'          => __('Unblock', 'advance-fake-order-protector'),
                'confirm_delete'   => __('আপনি কি নিশ্চিত এটি ডিলিট করতে চান?', 'advance-fake-order-protector'),
                'confirm_bulk_del' => __('নির্বাচিত রেকর্ডগুলো ডিলিট করতে চান?', 'advance-fake-order-protector'),
                'confirm_recovered'=> __('আপনি কি এটিকে Recovered হিসেবে মার্ক করতে চান?', 'advance-fake-order-protector')
            )
        ));
    }

    /**
     * Add Custom Columns to WooCommerce Orders Table
     */
    public function add_orders_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $title) {
            $new_columns[$key] = $title;
            if ($key === 'order_number' || $key === 'order_status') {
                $new_columns['afop_order_products']   = '<i class="fa-solid fa-box-open"></i> ' . __('Products', 'advance-fake-order-protector');
                $new_columns['afop_security_actions'] = '<i class="fa-solid fa-shield-halved"></i> ' . __('Security & Courier', 'advance-fake-order-protector');
            }
        }
        if (!isset($new_columns['afop_order_products'])) {
            $new_columns['afop_order_products'] = '<i class="fa-solid fa-box-open"></i> ' . __('Products', 'advance-fake-order-protector');
        }
        if (!isset($new_columns['afop_security_actions'])) {
            $new_columns['afop_security_actions'] = '<i class="fa-solid fa-shield-halved"></i> ' . __('Security & Courier', 'advance-fake-order-protector');
        }
        return $new_columns;
    }

    /**
     * Render Custom Columns for Classic CPT Orders
     */
    public function render_orders_column_content($column, $post_id) {
        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }

        if ($column === 'afop_order_products') {
            $this->render_order_products_column($order);
        } elseif ($column === 'afop_security_actions') {
            $this->render_security_action_buttons($order);
        }
    }

    /**
     * Render Custom Columns for HPOS Orders
     */
    public function render_hpos_orders_column_content($column, $order) {
        if (!$order) {
            return;
        }

        if ($column === 'afop_order_products') {
            $this->render_order_products_column($order);
        } elseif ($column === 'afop_security_actions') {
            $this->render_security_action_buttons($order);
        }
    }

    /**
     * Render Ordered Products with Image, Link & Quantity in Orders Table
     */
    public function render_order_products_column($order) {
        $items = $order->get_items();
        if (empty($items)) {
            echo '<span style="color: #94a3b8;">' . esc_html__('No items', 'advance-fake-order-protector') . '</span>';
            return;
        }
        ?>
        <div class="afop-order-products-list">
            <?php foreach ($items as $item): 
                $product = $item->get_product();
                $qty = $item->get_quantity();
                $product_name = $item->get_name();
                $product_id = $item->get_product_id();
                
                // Image
                $img_url = '';
                if ($product) {
                    $img_id = $product->get_image_id();
                    if ($img_id) {
                        $img_url = wp_get_attachment_image_url($img_id, 'thumbnail');
                    }
                }
                if (empty($img_url)) {
                    $img_url = wc_placeholder_img_src('thumbnail');
                }

                // Link to edit product in admin or frontend permalink
                $product_link = '';
                if ($product_id) {
                    $product_link = get_edit_post_link($product_id);
                    if (empty($product_link)) {
                        $product_link = get_permalink($product_id);
                    }
                }
            ?>
                <div class="afop-order-product-item">
                    <div class="afop-product-thumb">
                        <img src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr($product_name); ?>" width="36" height="36">
                    </div>
                    <div class="afop-product-details">
                        <?php if (!empty($product_link)): ?>
                            <a href="<?php echo esc_url($product_link); ?>" target="_blank" class="afop-product-name-link" title="<?php esc_attr_e('View / Edit Product', 'advance-fake-order-protector'); ?>">
                                <?php echo esc_html($product_name); ?>
                            </a>
                        <?php else: ?>
                            <span class="afop-product-name"><?php echo esc_html($product_name); ?></span>
                        <?php endif; ?>
                        <span class="afop-product-qty-badge">&times; <?php echo esc_html($qty); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render the Security Action Buttons & Phone in Orders List
     */
    public function render_security_action_buttons($order) {
        $phone = $order->get_billing_phone();
        $normalized_phone = AFOP_Validator::normalize_phone($phone);
        $client_ip = $order->get_customer_ip_address();
        $customer_name = $order->get_formatted_billing_full_name() ?: 'Customer';

        $phone_blocked = !empty($normalized_phone) ? AFOP_Blocklist::is_blocked('phone', $normalized_phone) : false;
        $ip_blocked = !empty($client_ip) ? AFOP_Blocklist::is_blocked('ip', $client_ip) : false;
        ?>
        <div class="afop-order-actions-wrap" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
            <?php if (!empty($normalized_phone)): ?>
                <!-- Customer Phone Display with Call Icon -->
                <div class="afop-phone-display-row">
                    <a href="tel:<?php echo esc_attr($normalized_phone); ?>" class="afop-phone-pill-link" title="<?php esc_attr_e('Call Customer', 'advance-fake-order-protector'); ?>">
                        <i class="fa-solid fa-phone"></i> <strong><?php echo esc_html($normalized_phone); ?></strong>
                    </a>
                </div>

                <!-- Phone Block Toggle -->
                <button type="button" 
                        class="afop-btn-action afop-toggle-block-btn <?php echo $phone_blocked ? 'is-blocked' : 'is-safe'; ?>" 
                        data-type="phone" 
                        data-value="<?php echo esc_attr($normalized_phone); ?>"
                        title="<?php echo $phone_blocked ? esc_attr__('Click to Unblock Phone', 'advance-fake-order-protector') : esc_attr__('Click to Block Phone', 'advance-fake-order-protector'); ?>">
                    <span class="afop-btn-icon"><i class="fa-solid <?php echo $phone_blocked ? 'fa-ban' : 'fa-phone-slash'; ?>"></i></span>
                    <span class="afop-btn-text"><?php echo $phone_blocked ? esc_html__('Blocked', 'advance-fake-order-protector') : esc_html__('Block Phone', 'advance-fake-order-protector'); ?></span>
                </button>

                <!-- Courier Ratio Checker Button -->
                <button type="button" 
                        class="afop-btn-action afop-check-courier-btn" 
                        data-phone="<?php echo esc_attr($normalized_phone); ?>"
                        data-name="<?php echo esc_attr($customer_name); ?>"
                        title="<?php echo esc_attr__('Check Courier Delivery Ratio', 'advance-fake-order-protector'); ?>">
                    <span class="afop-btn-icon"><i class="fa-solid fa-chart-pie"></i></span>
                    <span class="afop-btn-text"><?php esc_html_e('Courier Ratio', 'advance-fake-order-protector'); ?></span>
                </button>
            <?php endif; ?>

            <?php if (!empty($client_ip)): ?>
                <!-- IP Block Toggle -->
                <button type="button" 
                        class="afop-btn-action afop-toggle-block-btn <?php echo $ip_blocked ? 'is-blocked' : 'is-safe'; ?>" 
                        data-type="ip" 
                        data-value="<?php echo esc_attr($client_ip); ?>"
                        title="<?php echo $ip_blocked ? esc_attr__('Click to Unblock IP', 'advance-fake-order-protector') : esc_attr__('Click to Block IP', 'advance-fake-order-protector'); ?>">
                    <span class="afop-btn-icon"><i class="fa-solid <?php echo $ip_blocked ? 'fa-ban' : 'fa-globe'; ?>"></i></span>
                    <span class="afop-btn-text"><?php echo $ip_blocked ? esc_html__('IP Blocked', 'advance-fake-order-protector') : esc_html__('Block IP', 'advance-fake-order-protector'); ?></span>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Register Meta Box in Single Order Edit Page
     */
    public function register_order_meta_box() {
        $screen = 'shop_order';

        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
        }

        $screens = array_unique(array_filter(array('shop_order', $screen)));

        foreach ($screens as $s) {
            add_meta_box(
                'afop_order_security_meta_box',
                __('Fake Order Protector & Courier Ratio', 'advance-fake-order-protector'),
                array($this, 'render_order_meta_box_content'),
                $s,
                'side',
                'high'
            );
        }
    }

    /**
     * Render Single Order Meta Box Content
     */
    public function render_order_meta_box_content($post_or_order) {
        $order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) {
            return;
        }

        $phone = $order->get_billing_phone();
        $normalized_phone = AFOP_Validator::normalize_phone($phone);
        $client_ip = $order->get_customer_ip_address();
        $customer_name = $order->get_formatted_billing_full_name() ?: 'Customer';

        $phone_blocked = !empty($normalized_phone) ? AFOP_Blocklist::is_blocked('phone', $normalized_phone) : false;
        $ip_blocked = !empty($client_ip) ? AFOP_Blocklist::is_blocked('ip', $client_ip) : false;
        ?>
        <div class="afop-meta-box-wrap">
            <div class="afop-meta-row">
                <span class="afop-meta-label"><i class="fa-solid fa-phone"></i> <strong>Phone:</strong> <?php echo esc_html($phone ?: 'N/A'); ?></span>
                <?php if (!empty($normalized_phone)): ?>
                    <button type="button" 
                            class="afop-btn-action afop-toggle-block-btn <?php echo $phone_blocked ? 'is-blocked' : 'is-safe'; ?>" 
                            data-type="phone" 
                            data-value="<?php echo esc_attr($normalized_phone); ?>">
                        <span><i class="fa-solid <?php echo $phone_blocked ? 'fa-unlock' : 'fa-ban'; ?>"></i> <?php echo $phone_blocked ? 'Unblock Phone' : 'Block Phone'; ?></span>
                    </button>
                <?php endif; ?>
            </div>

            <div class="afop-meta-row">
                <span class="afop-meta-label"><i class="fa-solid fa-globe"></i> <strong>IP Address:</strong> <?php echo esc_html($client_ip ?: 'N/A'); ?></span>
                <?php if (!empty($client_ip)): ?>
                    <button type="button" 
                            class="afop-btn-action afop-toggle-block-btn <?php echo $ip_blocked ? 'is-blocked' : 'is-safe'; ?>" 
                            data-type="ip" 
                            data-value="<?php echo esc_attr($client_ip); ?>">
                        <span><i class="fa-solid <?php echo $ip_blocked ? 'fa-unlock' : 'fa-ban'; ?>"></i> <?php echo $ip_blocked ? 'Unblock IP' : 'Block IP'; ?></span>
                    </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($normalized_phone)): ?>
                <div class="afop-meta-row" style="margin-top: 12px;">
                    <button type="button" 
                            class="button button-primary afop-check-courier-btn" 
                            style="width: 100%; text-align: center;"
                            data-phone="<?php echo esc_attr($normalized_phone); ?>"
                            data-name="<?php echo esc_attr($customer_name); ?>">
                        <i class="fa-solid fa-chart-pie"></i> <?php esc_html_e('Check Courier Delivery Ratio', 'advance-fake-order-protector'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Courier Ratio Modal for Admin with 3 Provider Tabs
     */
    public function render_admin_courier_modal() {
        ?>
        <div id="afop-admin-courier-modal" class="afop-admin-modal-overlay" style="display:none;">
            <div class="afop-admin-modal-box">
                <div class="afop-admin-modal-header">
                    <div class="afop-modal-header-left">
                        <span class="afop-modal-badge"><i class="fa-solid fa-chart-line"></i> Delivery Intelligence</span>
                        <h2 id="afop-courier-customer-title"><?php esc_html_e('Courier Delivery History', 'advance-fake-order-protector'); ?></h2>
                        <span id="afop-courier-phone-badge" class="afop-phone-pill"></span>
                    </div>
                    <button type="button" class="afop-admin-modal-close" id="afop-close-courier-modal">&times;</button>
                </div>

                <!-- 3 Provider Tabs -->
                <div class="afop-courier-provider-tabs">
                    <button type="button" class="afop-courier-tab-btn active" data-provider="bdcourier">
                        <i class="fa-solid fa-truck-fast"></i> BD Courier
                    </button>
                    <button type="button" class="afop-courier-tab-btn" data-provider="steadfast">
                        <i class="fa-solid fa-bolt"></i> Steadfast
                    </button>
                    <button type="button" class="afop-courier-tab-btn" data-provider="fraudbd">
                        <i class="fa-solid fa-shield-halved"></i> FraudBD
                    </button>
                </div>

                <div class="afop-admin-modal-body">
                    <!-- Loader -->
                    <div id="afop-courier-loading" class="afop-loader-wrap" style="display:none;">
                        <div class="afop-spinner"></div>
                        <p><?php esc_html_e('কুরিয়ার ডাটা সংগ্রহ করা হচ্ছে...', 'advance-fake-order-protector'); ?></p>
                    </div>

                    <!-- Content Container -->
                    <div id="afop-courier-content" style="display:none;">
                        <!-- Demo Notice Banner if applicable -->
                        <div id="afop-demo-notice-banner" class="afop-notice-banner" style="display:none;"></div>

                        <!-- Top Stat Cards -->
                        <div class="afop-ratio-grid">
                            <!-- Circular Gauge / Delivery Rate -->
                            <div class="afop-card afop-gauge-card">
                                <div class="afop-gauge-wrap">
                                    <div class="afop-gauge-circle" id="afop-gauge-circle">
                                        <span class="afop-gauge-percent" id="afop-delivery-rate-text">0%</span>
                                        <span class="afop-gauge-sub">Delivery Rate</span>
                                    </div>
                                </div>
                                <div class="afop-risk-badge-wrap">
                                    <span id="afop-risk-badge" class="afop-risk-badge safe">
                                        <i class="fa-solid fa-circle-check"></i> Safe Customer
                                    </span>
                                </div>
                            </div>

                            <!-- Metrics -->
                            <div class="afop-card afop-metrics-card">
                                <div class="afop-metric-item success">
                                    <span class="afop-metric-val" id="afop-delivered-count">0</span>
                                    <span class="afop-metric-lbl"><i class="fa-solid fa-check"></i> Delivered Parcels</span>
                                </div>
                                <div class="afop-metric-item danger">
                                    <span class="afop-metric-val" id="afop-returned-count">0</span>
                                    <span class="afop-metric-lbl"><i class="fa-solid fa-xmark"></i> Returned / Cancelled</span>
                                </div>
                                <div class="afop-metric-item total">
                                    <span class="afop-metric-val" id="afop-total-count">0</span>
                                    <span class="afop-metric-lbl"><i class="fa-solid fa-box"></i> Total Parcels</span>
                                </div>
                                <div class="afop-metric-item return-rate">
                                    <span class="afop-metric-val" id="afop-return-rate-text">0%</span>
                                    <span class="afop-metric-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Return Rate</span>
                                </div>
                            </div>
                        </div>

                        <!-- Courier Breakdown Section -->
                        <div class="afop-breakdown-section">
                            <h4><i class="fa-solid fa-list-check"></i> <?php esc_html_e('Courier Breakdown', 'advance-fake-order-protector'); ?></h4>
                            <table class="afop-courier-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Courier Service', 'advance-fake-order-protector'); ?></th>
                                        <th><?php esc_html_e('Total Parcels', 'advance-fake-order-protector'); ?></th>
                                        <th><?php esc_html_e('Delivered', 'advance-fake-order-protector'); ?></th>
                                        <th><?php esc_html_e('Returned', 'advance-fake-order-protector'); ?></th>
                                        <th><?php esc_html_e('Success Rate', 'advance-fake-order-protector'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="afop-courier-breakdown-tbody">
                                    <!-- Dynamic rows -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="afop-admin-modal-footer">
                    <div class="afop-modal-footer-left">
                        <button type="button" class="button" id="afop-refresh-courier-btn"><i class="fa-solid fa-rotate"></i> <?php esc_html_e('Force Refresh', 'advance-fake-order-protector'); ?></button>
                    </div>
                    <div class="afop-modal-footer-right">
                        <button type="button" class="button button-danger" id="afop-modal-block-phone-btn"><i class="fa-solid fa-ban"></i> <?php esc_html_e('Block This Phone', 'advance-fake-order-protector'); ?></button>
                        <button type="button" class="button" id="afop-modal-close-footer-btn"><?php esc_html_e('Close', 'advance-fake-order-protector'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Toast Notification Container -->
        <div id="afop-toast-container" class="afop-toast-container"></div>
        <?php
    }

    /**
     * Handle Settings Form Submission
     */
    public function handle_save_settings() {
        if (!isset($_POST['afop_save_settings']) || !wp_verify_nonce($_POST['afop_settings_nonce'], 'afop_save_settings_action')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $fields = array(
            'afop_enable_bd_validation'     => 'yes_no',
            'afop_strict_fake_patterns'     => 'yes_no',
            'afop_invalid_phone_title'      => 'text',
            'afop_invalid_phone_msg'        => 'textarea',
            'afop_enable_blocklist'         => 'yes_no',
            'afop_blocked_title'            => 'text',
            'afop_blocked_msg'              => 'textarea',
            'afop_whatsapp_number'          => 'text',
            'afop_whatsapp_message'         => 'textarea',
            'afop_whatsapp_btn_text'        => 'text',
            'afop_enable_repeat_check'      => 'yes_no',
            'afop_repeat_time_limit'        => 'int',
            'afop_repeat_order_title'       => 'text',
            'afop_repeat_order_msg'         => 'textarea',
            'afop_confirm_btn_text'         => 'text',
            'afop_cancel_btn_text'          => 'text',
            'afop_enable_incomplete_capture'=> 'yes_no',
            'afop_courier_provider'         => 'text',
            'afop_bdcourier_api_key'        => 'text',
            'afop_steadfast_api_key'        => 'text',
            'afop_steadfast_secret_key'     => 'text',
            'afop_fraudbd_api_key'          => 'text',
            'afop_courier_cache_hours'      => 'int'
        );

        foreach ($fields as $field => $type) {
            if ($type === 'yes_no') {
                $val = isset($_POST[$field]) ? 'yes' : 'no';
                update_option($field, $val);
            } elseif ($type === 'int') {
                $val = isset($_POST[$field]) ? intval($_POST[$field]) : 0;
                update_option($field, $val);
            } elseif ($type === 'textarea') {
                $val = isset($_POST[$field]) ? sanitize_textarea_field(wp_unslash($_POST[$field])) : '';
                update_option($field, $val);
            } else {
                $val = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
                update_option($field, $val);
            }
        }

        add_settings_error('afop_messages', 'afop_message', __('Settings saved successfully!', 'advance-fake-order-protector'), 'updated');
    }

    /**
     * Render Settings Page
     */
    public function render_settings_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        settings_errors('afop_messages');
        ?>
        <div class="wrap afop-admin-wrap">
            <div class="afop-header-banner">
                <div class="afop-header-content">
                    <h1><i class="fa-solid fa-shield-halved"></i> Advance Fake Order Protector & Courier Checker</h1>
                    <p>WooCommerce Fake Order Defense, Bangladeshi Phone Verification, Repeat Order Blocker & Multi-Courier Ratio Analysis</p>
                </div>
            </div>

            <h2 class="nav-tab-wrapper">
                <a href="?page=afop-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>"><i class="fa-solid fa-gear"></i> General & WhatsApp</a>
                <a href="?page=afop-settings&tab=validation" class="nav-tab <?php echo $active_tab === 'validation' ? 'nav-tab-active' : ''; ?>"><i class="fa-solid fa-mobile-screen"></i> BD Phone Validator</a>
                <a href="?page=afop-settings&tab=repeat" class="nav-tab <?php echo $active_tab === 'repeat' ? 'nav-tab-active' : ''; ?>"><i class="fa-solid fa-repeat"></i> Repeat Order Protection</a>
                <a href="?page=afop-settings&tab=courier" class="nav-tab <?php echo $active_tab === 'courier' ? 'nav-tab-active' : ''; ?>"><i class="fa-solid fa-truck-fast"></i> Courier API Settings</a>
            </h2>

            <form method="post" action="">
                <?php wp_nonce_field('afop_save_settings_action', 'afop_settings_nonce'); ?>

                <?php if ($active_tab === 'general'): ?>
                    <div class="afop-settings-section">
                        <h3><i class="fa-brands fa-whatsapp"></i> General & WhatsApp Support Configuration</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">WhatsApp Support Number</th>
                                <td>
                                    <input type="text" name="afop_whatsapp_number" value="<?php echo esc_attr(get_option('afop_whatsapp_number', '')); ?>" class="regular-text" placeholder="017XXXXXXXX বা 88017XXXXXXXX">
                                    <p class="description">ব্লক করা কাস্টমাররা চেকআউটে এই নম্বরে হোয়াটসঅ্যাপে সরাসরি মেসেজ করতে পারবে।</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">WhatsApp Message</th>
                                <td>
                                    <textarea name="afop_whatsapp_message" rows="3" class="large-text"><?php echo esc_textarea(get_option('afop_whatsapp_message', 'Hello Support, my order checkout is blocked. Please help me.')); ?></textarea>
                                    <p class="description">হোয়াটসঅ্যাপে ক্লিক করলে যে ডিফল্ট মেসেজ প্রস্তুত থাকবে।</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">WhatsApp Button Text</th>
                                <td>
                                    <input type="text" name="afop_whatsapp_btn_text" value="<?php echo esc_attr(get_option('afop_whatsapp_btn_text', 'হোয়াটসঅ্যাপে যোগাযোগ করুন')); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Enable IP & Phone Blocklist</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="afop_enable_blocklist" value="yes" <?php checked(get_option('afop_enable_blocklist', 'yes'), 'yes'); ?>>
                                        অর্ডার টেবিল থেকে ১-ক্লিকে আইপি বা ফোন নম্বর ব্লক ও আনব্লক করার সুবিধা চালু রাখুন।
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Blocked Modal Title</th>
                                <td>
                                    <input type="text" name="afop_blocked_title" value="<?php echo esc_attr(get_option('afop_blocked_title', 'অর্ডার ব্লক করা হয়েছে!')); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Blocked Modal Message</th>
                                <td>
                                    <textarea name="afop_blocked_msg" rows="3" class="large-text"><?php echo esc_textarea(get_option('afop_blocked_msg', 'দুঃখিত, আপনার আইপি বা মোবাইল নম্বরটি নিরাপত্তা কারণে ব্লক করা আছে। সাহায্যের জন্য আমাদের সাপোর্টে যোগাযোগ করুন।')); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Enable Real-Time Incomplete Capture</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="afop_enable_incomplete_capture" value="yes" <?php checked(get_option('afop_enable_incomplete_capture', 'yes'), 'yes'); ?>>
                                        চেকআউটে ১১ ডিজিটের ফোন নম্বর টাইপ করার সাথে সাথে ইনকমপ্লিট অর্ডার হিসেবে লিড সেভ করুন।
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                <?php elseif ($active_tab === 'validation'): ?>
                    <div class="afop-settings-section">
                        <h3><i class="fa-solid fa-mobile-screen"></i> Bangladeshi Mobile Number Validation Rules</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Enable BD Phone Validation</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="afop_enable_bd_validation" value="yes" <?php checked(get_option('afop_enable_bd_validation', 'yes'), 'yes'); ?>>
                                        চেকআউটে শুধুমাত্র সঠিক ১১ ডিজিটের বাংলাদেশী মোবাইল নম্বর (013-019) গ্রহণ করুন।
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Strict Fake Pattern Blocker</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="afop_strict_fake_patterns" value="yes" <?php checked(get_option('afop_strict_fake_patterns', 'yes'), 'yes'); ?>>
                                        ডামি/টেস্ট নম্বর (যেমন: 01700000000, 01234567890, 01711111111) স্বয়ংক্রিয়ভাবে ব্লক করুন।
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Invalid Phone Modal Title</th>
                                <td>
                                    <input type="text" name="afop_invalid_phone_title" value="<?php echo esc_attr(get_option('afop_invalid_phone_title', 'ভুল মোবাইল নম্বর!')); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Invalid Phone Error Message</th>
                                <td>
                                    <textarea name="afop_invalid_phone_msg" rows="3" class="large-text"><?php echo esc_textarea(get_option('afop_invalid_phone_msg', 'আপনার নম্বরটি ভুল। দয়া করে সঠিক ১১ ডিজিটের বাংলাদেশী মোবাইল নম্বর লিখুন।')); ?></textarea>
                                </td>
                            </tr>
                        </table>
                    </div>

                <?php elseif ($active_tab === 'repeat'): ?>
                    <div class="afop-settings-section">
                        <h3><i class="fa-solid fa-repeat"></i> Repeat / Duplicate Order Protection</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Enable Repeat Order Protection</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="afop_enable_repeat_check" value="yes" <?php checked(get_option('afop_enable_repeat_check', 'yes'), 'yes'); ?>>
                                        একই কাস্টমার নির্দিষ্ট সময়ের মধ্যে একই প্রোডাক্ট ২য় বার অর্ডার করতে গেলে কনফার্মেশন পপআপ দেখাবে।
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Repeat Order Time Limit (Minutes)</th>
                                <td>
                                    <input type="number" name="afop_repeat_time_limit" value="<?php echo esc_attr(get_option('afop_repeat_time_limit', 60)); ?>" class="small-text" min="1" max="1440"> মিনিট
                                    <p class="description">ডিফল্ট: ৬০ মিনিট (১ ঘণ্টা)। এই সময়ের মধ্যে একই প্রোডাক্ট পুনরায় অর্ডার করতে গেলে সতর্ক করবে।</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Repeat Order Modal Title</th>
                                <td>
                                    <input type="text" name="afop_repeat_order_title" value="<?php echo esc_attr(get_option('afop_repeat_order_title', 'পুনরায় অর্ডার নিশ্চিতকরণ')); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Repeat Order Warning Message</th>
                                <td>
                                    <textarea name="afop_repeat_order_msg" rows="3" class="large-text"><?php echo esc_textarea(get_option('afop_repeat_order_msg', 'আপনি {minutes} মিনিট আগে এই প্রোডাক্টটি অর্ডার করেছিলেন। আপনি কি আবার এই একই প্রোডাক্ট অর্ডার করতে চান?')); ?></textarea>
                                    <p class="description">ব্যবহার করুন <code>{minutes}</code> কত মিনিট আগে অর্ডার করেছিল তা স্বয়ংক্রিয়ভাবে দেখাতে।</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Confirm Button Text</th>
                                <td>
                                    <input type="text" name="afop_confirm_btn_text" value="<?php echo esc_attr(get_option('afop_confirm_btn_text', 'হ্যাঁ, আবার অর্ডার করুন')); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Cancel Button Text</th>
                                <td>
                                    <input type="text" name="afop_cancel_btn_text" value="<?php echo esc_attr(get_option('afop_cancel_btn_text', 'না, বাতিল করুন')); ?>" class="regular-text">
                                </td>
                            </tr>
                        </table>
                    </div>

                <?php elseif ($active_tab === 'courier'): ?>
                    <div class="afop-settings-section">
                        <h3><i class="fa-solid fa-truck-fast"></i> Courier API & Fraud Ratio Configuration</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Primary Default Provider</th>
                                <td>
                                    <select name="afop_courier_provider" id="afop_courier_provider">
                                        <option value="bdcourier" <?php selected(get_option('afop_courier_provider', 'bdcourier'), 'bdcourier'); ?>>BD Courier API (api.bdcourier.com)</option>
                                        <option value="steadfast" <?php selected(get_option('afop_courier_provider', 'bdcourier'), 'steadfast'); ?>>Steadfast Courier API</option>
                                        <option value="fraudbd" <?php selected(get_option('afop_courier_provider', 'bdcourier'), 'fraudbd'); ?>>FraudBD API (fraudbd.com)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">BD Courier API Key (Bearer Token)</th>
                                <td>
                                    <input type="password" name="afop_bdcourier_api_key" value="<?php echo esc_attr(get_option('afop_bdcourier_api_key', '')); ?>" class="large-text" placeholder="BD Courier থেকে প্রাপ্ত API Key দিন">
                                    <p class="description">bdcourier.com থেকে আপনার API Key সংগ্রহ করুন।</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Steadfast API Key</th>
                                <td>
                                    <input type="password" name="afop_steadfast_api_key" value="<?php echo esc_attr(get_option('afop_steadfast_api_key', '')); ?>" class="large-text" placeholder="Steadfast API Key">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Steadfast Secret Key</th>
                                <td>
                                    <input type="password" name="afop_steadfast_secret_key" value="<?php echo esc_attr(get_option('afop_steadfast_secret_key', '')); ?>" class="large-text" placeholder="Steadfast Secret Key">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">FraudBD API Key</th>
                                <td>
                                    <input type="password" name="afop_fraudbd_api_key" value="<?php echo esc_attr(get_option('afop_fraudbd_api_key', '')); ?>" class="large-text" placeholder="FraudBD API Key">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Cache Duration (Hours)</th>
                                <td>
                                    <input type="number" name="afop_courier_cache_hours" value="<?php echo esc_attr(get_option('afop_courier_cache_hours', 24)); ?>" class="small-text" min="1" max="168"> ঘণ্টা
                                    <p class="description">কুরিয়ার হিস্ট্রি ক্যাশে থাকবে যেন প্রতিবার নতুন API কল করতে না হয়।</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Test Connection</th>
                                <td>
                                    <button type="button" class="button" id="afop-test-api-btn"><i class="fa-solid fa-plug"></i> Test Courier API Connection</button>
                                    <span id="afop-test-api-result" style="margin-left: 10px;"></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                <?php endif; ?>

                <p class="submit">
                    <input type="submit" name="afop_save_settings" id="submit" class="button button-primary button-large" value="<?php esc_attr_e('Save Changes', 'advance-fake-order-protector'); ?>">
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render Incomplete Orders Page
     */
    public function render_incomplete_orders_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'afop_incomplete_orders';

        // Stats
        $total_leads = $wpdb->get_var("SELECT COUNT(id) FROM {$table} WHERE status = 'incomplete'");
        $total_converted = $wpdb->get_var("SELECT COUNT(id) FROM {$table} WHERE status = 'converted'");
        $total_recovered = $wpdb->get_var("SELECT COUNT(id) FROM {$table} WHERE status = 'recovered'");
        $lost_revenue = $wpdb->get_var("SELECT SUM(cart_total) FROM {$table} WHERE status = 'incomplete'") ?: 0.00;

        // Fetch active incomplete items
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $filter_status = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : 'incomplete';

        $where = "WHERE 1=1";
        if ($filter_status !== 'all') {
            $where .= $wpdb->prepare(" AND status = %s", $filter_status);
        }
        if (!empty($search)) {
            $where .= $wpdb->prepare(" AND (phone LIKE %s OR name LIKE %s OR address LIKE %s)", '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
        }

        $items = $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY updated_at DESC LIMIT 100");
        ?>
        <div class="wrap afop-admin-wrap">
            <div class="afop-header-banner">
                <div class="afop-header-content">
                    <h1><i class="fa-solid fa-cart-arrow-down"></i> Real-Time Incomplete Orders & Abandoned Leads</h1>
                    <p>কাস্টমার চেকআউটে ১১ ডিজিটের ফোন নম্বর টাইপ করার সাথে সাথে স্বয়ংক্রিয়ভাবে সংগৃহীত লিড সমূহ।</p>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="afop-stats-row">
                <div class="afop-stat-card warning">
                    <span class="afop-stat-num"><?php echo intval($total_leads); ?></span>
                    <span class="afop-stat-label"><i class="fa-solid fa-cart-shopping"></i> Pending Incomplete</span>
                </div>
                <div class="afop-stat-card danger">
                    <span class="afop-stat-num"><?php echo wc_price($lost_revenue); ?></span>
                    <span class="afop-stat-label"><i class="fa-solid fa-sack-dollar"></i> Potential Lost Value</span>
                </div>
                <div class="afop-stat-card success">
                    <span class="afop-stat-num"><?php echo intval($total_converted); ?></span>
                    <span class="afop-stat-label"><i class="fa-solid fa-circle-check"></i> Converted to Orders</span>
                </div>
                <div class="afop-stat-card info">
                    <span class="afop-stat-num"><?php echo intval($total_recovered); ?></span>
                    <span class="afop-stat-label"><i class="fa-solid fa-hand-holding-dollar"></i> Manually Recovered</span>
                </div>
            </div>

            <!-- Filter & Search Bar -->
            <div class="tablenav top" style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                <ul class="subsubsub" style="margin: 0;">
                    <li><a href="?page=afop-incomplete-orders&status_filter=incomplete" class="<?php echo $filter_status === 'incomplete' ? 'current' : ''; ?>">Pending Incomplete <span class="count">(<?php echo intval($total_leads); ?>)</span></a> |</li>
                    <li><a href="?page=afop-incomplete-orders&status_filter=converted" class="<?php echo $filter_status === 'converted' ? 'current' : ''; ?>">Converted <span class="count">(<?php echo intval($total_converted); ?>)</span></a> |</li>
                    <li><a href="?page=afop-incomplete-orders&status_filter=recovered" class="<?php echo $filter_status === 'recovered' ? 'current' : ''; ?>">Recovered <span class="count">(<?php echo intval($total_recovered); ?>)</span></a> |</li>
                    <li><a href="?page=afop-incomplete-orders&status_filter=all" class="<?php echo $filter_status === 'all' ? 'current' : ''; ?>">All Records</a></li>
                </ul>

                <form method="get" action="">
                    <input type="hidden" name="page" value="afop-incomplete-orders">
                    <input type="hidden" name="status_filter" value="<?php echo esc_attr($filter_status); ?>">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search by phone, name...">
                    <button type="submit" class="button"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                </form>
            </div>

            <!-- Leads Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 220px;">Customer & Contact</th>
                        <th style="width: 200px;">Location & IP</th>
                        <th>Cart Items</th>
                        <th style="width: 100px;">Total</th>
                        <th style="width: 120px;">Time</th>
                        <th style="width: 100px;">Status</th>
                        <th style="width: 160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding: 25px;"><?php esc_html_e('কোন ইনকমপ্লিট অর্ডার পাওয়া যায়নি।', 'advance-fake-order-protector'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): 
                            $clean_phone = preg_replace('/[^0-9]/', '', $item->phone);
                            if (substr($clean_phone, 0, 2) !== '88' && strlen($clean_phone) === 11) {
                                $wa_num = '88' . $clean_phone;
                            } else {
                                $wa_num = $clean_phone;
                            }
                            $wa_msg = rawurlencode(sprintf('Hello %s, we noticed you started an order on our store. Do you need any assistance?', $item->name ?: 'Customer'));
                            $cart_items = json_decode($item->cart_data, true) ?: array();
                        ?>
                            <tr id="afop-incomplete-row-<?php echo esc_attr($item->id); ?>">
                                <td>
                                    <strong><?php echo esc_html($item->name ?: 'Unknown Name'); ?></strong><br>
                                    <span class="afop-phone-tag"><i class="fa-solid fa-phone"></i> <?php echo esc_html($item->phone); ?></span><br>
                                    <?php if (!empty($item->email)): ?>
                                        <small><i class="fa-solid fa-envelope"></i> <?php echo esc_html($item->email); ?></small><br>
                                    <?php endif; ?>
                                    <div class="afop-quick-contact" style="margin-top: 6px;">
                                        <a href="tel:<?php echo esc_attr($item->phone); ?>" class="button button-small"><i class="fa-solid fa-phone"></i> Call</a>
                                        <a href="https://wa.me/<?php echo esc_attr($wa_num); ?>?text=<?php echo $wa_msg; ?>" target="_blank" class="button button-small afop-btn-whatsapp"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($item->address)): ?>
                                        <p style="margin: 0; font-size: 12px;"><?php echo nl2br(esc_html($item->address)); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($item->city)): ?>
                                        <small><strong>City:</strong> <?php echo esc_html($item->city); ?></small><br>
                                    <?php endif; ?>
                                    <small style="color: #888;">IP: <?php echo esc_html($item->ip_address); ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($cart_items)): ?>
                                        <div class="afop-cart-preview-list">
                                            <?php foreach ($cart_items as $c_item): ?>
                                                <div class="afop-cart-thumb-item">
                                                    <?php if (!empty($c_item['image'])): ?>
                                                        <img src="<?php echo esc_url($c_item['image']); ?>" width="32" height="32" style="border-radius: 4px; vertical-align: middle; margin-right: 6px;">
                                                    <?php endif; ?>
                                                    <span><?php echo esc_html($c_item['name']); ?> &times; <strong><?php echo esc_html($c_item['quantity']); ?></strong></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <em>Cart empty</em>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo wc_price($item->cart_total); ?></strong></td>
                                <td><small><?php echo human_time_diff(strtotime($item->updated_at), current_time('timestamp')); ?> ago</small></td>
                                <td>
                                    <span class="afop-badge-status status-<?php echo esc_attr($item->status); ?>">
                                        <?php echo ucfirst($item->status); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="button button-small afop-check-courier-btn" data-phone="<?php echo esc_attr($item->phone); ?>" data-name="<?php echo esc_attr($item->name); ?>"><i class="fa-solid fa-chart-pie"></i> Ratio</button>
                                    <?php if ($item->status === 'incomplete'): ?>
                                        <button type="button" class="button button-small afop-btn-mark-recovered" data-id="<?php echo esc_attr($item->id); ?>" title="Mark Recovered"><i class="fa-solid fa-check"></i></button>
                                    <?php endif; ?>
                                    <button type="button" class="button button-small button-link-delete afop-btn-delete-lead" data-id="<?php echo esc_attr($item->id); ?>" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render Blocklist Manager Page with Bulk Block, Export & Import
     */
    public function render_blocklist_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'afop_blocklist';

        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $type_filter = isset($_GET['type_filter']) ? sanitize_text_field($_GET['type_filter']) : 'all';

        $where = "WHERE 1=1";
        if ($type_filter !== 'all') {
            $where .= $wpdb->prepare(" AND type = %s", $type_filter);
        }
        if (!empty($search)) {
            $where .= $wpdb->prepare(" AND (value LIKE %s OR reason LIKE %s)", '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
        }

        $items = $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY updated_at DESC LIMIT 150");

        $export_url = wp_nonce_url(admin_url('admin-post.php?action=afop_export_blocklist_csv'), 'afop_export_blocklist_nonce');
        ?>
        <div class="wrap afop-admin-wrap">
            <div class="afop-header-banner">
                <div class="afop-header-content">
                    <h1><i class="fa-solid fa-ban"></i> Blocklist Manager (IP & Phone Blacklist)</h1>
                    <p>Manage blocked phone numbers and IP addresses. Blocked entries cannot complete checkout.</p>
                </div>
            </div>

            <!-- Action Toolbar: Bulk Block, Export CSV, Import CSV -->
            <div class="afop-toolbar-row">
                <div class="afop-toolbar-left">
                    <button type="button" class="button button-primary" id="afop-toggle-single-block"><i class="fa-solid fa-plus"></i> Add Single</button>
                    <button type="button" class="button" id="afop-toggle-bulk-block"><i class="fa-solid fa-layer-group"></i> Bulk Block (Paste Multiple)</button>
                    <button type="button" class="button" id="afop-toggle-import-block"><i class="fa-solid fa-file-import"></i> Import CSV/TXT</button>
                </div>
                <div class="afop-toolbar-right">
                    <a href="<?php echo esc_url($export_url); ?>" class="button button-secondary"><i class="fa-solid fa-file-export"></i> Export to CSV</a>
                    <button type="button" class="button button-link-delete" id="afop-bulk-delete-btn" style="display:none;"><i class="fa-solid fa-trash-can"></i> Delete Selected</button>
                </div>
            </div>

            <!-- Single Add Panel -->
            <div id="afop-single-block-panel" class="afop-card-panel" style="margin-top: 15px;">
                <h3><i class="fa-solid fa-plus-circle"></i> Add Single Phone or IP to Blocklist</h3>
                <form id="afop-add-block-form" class="afop-inline-form">
                    <select id="afop-new-block-type">
                        <option value="phone">Phone Number</option>
                        <option value="ip">IP Address</option>
                    </select>
                    <input type="text" id="afop-new-block-value" placeholder="e.g. 017XXXXXXXX or 103.xxx.xxx.xxx" required class="regular-text">
                    <input type="text" id="afop-new-block-reason" placeholder="Reason (e.g. Fake order / Return fraud)" class="regular-text">
                    <button type="submit" class="button button-primary"><i class="fa-solid fa-ban"></i> Block Now</button>
                </form>
            </div>

            <!-- Bulk Block Panel (Collapsible) -->
            <div id="afop-bulk-block-panel" class="afop-card-panel" style="margin-top: 15px; display: none;">
                <h3><i class="fa-solid fa-layer-group"></i> Bulk Add Phone Numbers or IP Addresses</h3>
                <p class="description">একসাথে অনেকগুলো ফোন নম্বর বা আইপি পেস্ট করুন (প্রতি লাইনে ১টি করে অথবা কমা দিয়ে পৃথক করুন)।</p>
                <form id="afop-bulk-block-form">
                    <div style="margin-bottom: 10px;">
                        <label><strong>Type:</strong></label>
                        <select id="afop-bulk-block-type">
                            <option value="phone">Phone Numbers</option>
                            <option value="ip">IP Addresses</option>
                        </select>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <textarea id="afop-bulk-block-values" rows="6" class="large-text" placeholder="01711111111&#10;01822222222&#10;01933333333" required></textarea>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <input type="text" id="afop-bulk-block-reason" placeholder="Reason for bulk block (Optional)" class="large-text">
                    </div>
                    <button type="submit" class="button button-primary"><i class="fa-solid fa-ban"></i> Bulk Block All</button>
                    <button type="button" class="button" id="afop-cancel-bulk-btn">Cancel</button>
                </form>
            </div>

            <!-- Import CSV Panel (Collapsible) -->
            <div id="afop-import-block-panel" class="afop-card-panel" style="margin-top: 15px; display: none;">
                <h3><i class="fa-solid fa-file-import"></i> Import Blocklist from CSV or Text File</h3>
                <p class="description">ফাইল আপলোড করে এক ক্লিকে ব্লকলিস্ট ইমপোর্ট করুন। ফরম্যাট: <code>Type,Value,Reason</code> অথবা শুধুমাত্র ফোন নম্বর/আইপি এর তালিকা।</p>
                <form id="afop-import-block-form" enctype="multipart/form-data">
                    <div style="margin-bottom: 12px;">
                        <input type="file" id="afop-import-file" accept=".csv,.txt" required>
                    </div>
                    <button type="submit" class="button button-primary"><i class="fa-solid fa-upload"></i> Start Import</button>
                    <button type="button" class="button" id="afop-cancel-import-btn">Cancel</button>
                    <span id="afop-import-status" style="margin-left: 10px;"></span>
                </form>
            </div>

            <!-- Filter & Search Bar -->
            <div class="tablenav top" style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
                <ul class="subsubsub" style="margin: 0;">
                    <li><a href="?page=afop-blocklist&type_filter=all" class="<?php echo $type_filter === 'all' ? 'current' : ''; ?>">All Records</a> |</li>
                    <li><a href="?page=afop-blocklist&type_filter=phone" class="<?php echo $type_filter === 'phone' ? 'current' : ''; ?>">Phones</a> |</li>
                    <li><a href="?page=afop-blocklist&type_filter=ip" class="<?php echo $type_filter === 'ip' ? 'current' : ''; ?>">IPs</a></li>
                </ul>

                <form method="get" action="">
                    <input type="hidden" name="page" value="afop-blocklist">
                    <input type="hidden" name="type_filter" value="<?php echo esc_attr($type_filter); ?>">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search blocklist...">
                    <button type="submit" class="button"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td id="cb" class="manage-column column-cb check-column">
                            <input id="cb-select-all" type="checkbox">
                        </td>
                        <th style="width: 100px;">Type</th>
                        <th>Value (Phone / IP)</th>
                        <th>Reason</th>
                        <th style="width: 120px;">Status</th>
                        <th style="width: 140px;">Date Added</th>
                        <th style="width: 130px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding: 25px;"><?php esc_html_e('ব্লকলিস্টে কোনো তথ্য নেই।', 'advance-fake-order-protector'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <tr id="afop-block-row-<?php echo esc_attr($item->id); ?>">
                                <th scope="row" class="check-column">
                                    <input type="checkbox" class="afop-block-cb" value="<?php echo esc_attr($item->id); ?>">
                                </th>
                                <td>
                                    <span class="afop-type-pill pill-<?php echo esc_attr($item->type); ?>">
                                        <i class="fa-solid <?php echo ($item->type === 'phone') ? 'fa-phone' : 'fa-globe'; ?>"></i>
                                        <?php echo ($item->type === 'phone') ? 'Phone' : 'IP'; ?>
                                    </span>
                                </td>
                                <td><strong><code><?php echo esc_html($item->value); ?></code></strong></td>
                                <td><?php echo esc_html($item->reason ?: 'No reason given'); ?></td>
                                <td>
                                    <span class="afop-badge-status status-<?php echo esc_attr($item->status); ?>">
                                        <?php echo ucfirst($item->status); ?>
                                    </span>
                                </td>
                                <td><small><?php echo esc_html(date_i18n('M j, Y H:i', strtotime($item->created_at))); ?></small></td>
                                <td>
                                    <button type="button" 
                                            class="button button-small afop-toggle-block-btn <?php echo ($item->status === 'blocked') ? 'is-blocked' : 'is-safe'; ?>" 
                                            data-type="<?php echo esc_attr($item->type); ?>" 
                                            data-value="<?php echo esc_attr($item->value); ?>">
                                        <i class="fa-solid <?php echo ($item->status === 'blocked') ? 'fa-unlock' : 'fa-ban'; ?>"></i>
                                        <?php echo ($item->status === 'blocked') ? 'Unblock' : 'Block'; ?>
                                    </button>
                                    <button type="button" class="button button-small button-link-delete afop-btn-delete-block" data-id="<?php echo esc_attr($item->id); ?>"><i class="fa-solid fa-trash-can"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
