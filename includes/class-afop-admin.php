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

        // Admin Footer Courier Modal & Customer Orders Modal
        add_action('admin_footer', array($this, 'render_admin_courier_modal'));

        // Save Settings Handler
        add_action('admin_init', array($this, 'handle_save_settings'));

        // Customer Store Orders AJAX
        add_action('wp_ajax_afop_get_customer_orders_ajax', array($this, 'ajax_get_customer_orders'));
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
            __('SARKAR IT Fake protection', 'advance-fake-order-protector'),
            __('SARKAR IT Protection', 'advance-fake-order-protector'),
            'manage_woocommerce',
            'afop-settings',
            array($this, 'render_settings_page'),
            'dashicons-shield-alt',
            56
        );

        add_submenu_page(
            'afop-settings',
            __('Settings & Dashboard - SARKAR IT', 'advance-fake-order-protector'),
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
    /**
     * Add Custom Columns to WooCommerce Orders Table
     */
    public function add_orders_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $title) {
            $new_columns[$key] = $title;
            if ($key === 'order_number' || $key === 'order_status') {
                $new_columns['afop_order_products']   = '<i class="fa-solid fa-box-open"></i> ' . __('Products', 'advance-fake-order-protector');
                $new_columns['afop_courier_ratio']    = '<i class="fa-solid fa-chart-pie"></i> ' . __('Courier Ratio', 'advance-fake-order-protector');
                $new_columns['afop_security_actions'] = '<i class="fa-solid fa-shield-halved"></i> ' . __('Security Actions', 'advance-fake-order-protector');
            }
        }
        if (!isset($new_columns['afop_order_products'])) {
            $new_columns['afop_order_products'] = '<i class="fa-solid fa-box-open"></i> ' . __('Products', 'advance-fake-order-protector');
        }
        if (!isset($new_columns['afop_courier_ratio'])) {
            $new_columns['afop_courier_ratio'] = '<i class="fa-solid fa-chart-pie"></i> ' . __('Courier Ratio', 'advance-fake-order-protector');
        }
        if (!isset($new_columns['afop_security_actions'])) {
            $new_columns['afop_security_actions'] = '<i class="fa-solid fa-shield-halved"></i> ' . __('Security Actions', 'advance-fake-order-protector');
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
        } elseif ($column === 'afop_courier_ratio') {
            $this->render_courier_ratio_column($order);
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
        } elseif ($column === 'afop_courier_ratio') {
            $this->render_courier_ratio_column($order);
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
     * Render Dedicated Courier Ratio Mini Graph Column in Orders List
     */
    /**
     * Render Dedicated Courier Ratio Mini Graph Column in Orders List
     */
    public function render_courier_ratio_column($order) {
        $phone = $order->get_billing_phone();
        $normalized_phone = AFOP_Validator::normalize_phone($phone);
        $customer_name = $order->get_formatted_billing_full_name() ?: 'Customer';

        if (empty($normalized_phone)) {
            echo '<span style="color: #94a3b8; font-size: 11px;">' . esc_html__('No Phone', 'advance-fake-order-protector') . '</span>';
            return;
        }

        $active_provider = get_option('afop_courier_provider', 'bdcourier');
        $has_api_configured = AFOP_Courier_Checker::is_provider_configured($active_provider) || AFOP_Courier_Checker::has_any_api_configured();

        if (!$has_api_configured) {
            ?>
            <div class="afop-courier-mini-graph afop-no-api-box" title="<?php esc_attr_e('আপনি কোনো কুরিয়ার API অ্যাড করেন নাই। সেটিংস থেকে API Key যুক্ত করুন।', 'advance-fake-order-protector'); ?>">
                <div class="afop-mini-chart-ring" style="border-color: #f59e0b; background: #fffbebfb;">
                    <i class="fa-solid fa-triangle-exclamation" style="color: #d97706; font-size: 15px;"></i>
                </div>
                <div class="afop-mini-graph-info">
                    <div class="afop-mini-graph-header">
                        <span class="afop-mini-rate-text" style="color: #d97706; font-size: 11.5px; font-weight: 700;"><?php esc_html_e('API Key নেই', 'advance-fake-order-protector'); ?></span>
                    </div>
                    <div class="afop-mini-graph-sub" style="margin-top: 2px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=afop-settings')); ?>" style="color: #2563eb; font-size: 11px; text-decoration: underline;">
                            <i class="fa-solid fa-gear"></i> <?php esc_html_e('API যুক্ত করুন', 'advance-fake-order-protector'); ?>
                        </a>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        // Fetch quick cached courier delivery stats
        $courier_stat = AFOP_Courier_Checker::get_quick_courier_stat($normalized_phone);
        $has_data = !empty($courier_stat) && !empty($courier_stat['has_api']) && isset($courier_stat['delivery_rate']);
        $rate_num = $has_data ? round(floatval($courier_stat['delivery_rate'])) : 0;
        $total_orders = $has_data && isset($courier_stat['total_orders']) ? intval($courier_stat['total_orders']) : 0;
        $delivered = $has_data && isset($courier_stat['delivered']) ? intval($courier_stat['delivered']) : 0;
        $returned = $has_data && isset($courier_stat['returned']) ? intval($courier_stat['returned']) : 0;

        $risk_level = 'neutral';
        $risk_label = __('Check', 'advance-fake-order-protector');

        if ($has_data) {
            if (isset($courier_stat['risk_level'])) {
                $risk_level = $courier_stat['risk_level'];
            } elseif ($rate_num >= 75) {
                $risk_level = 'safe';
            } elseif ($rate_num >= 50) {
                $risk_level = 'medium';
            } else {
                $risk_level = 'high';
            }

            if ($risk_level === 'safe') {
                $risk_label = __('Safe', 'advance-fake-order-protector');
            } elseif ($risk_level === 'medium') {
                $risk_label = __('Medium', 'advance-fake-order-protector');
            } else {
                $risk_label = __('Risky', 'advance-fake-order-protector');
            }
        }
        ?>
        <button type="button" 
                class="afop-courier-mini-graph afop-check-courier-btn risk-<?php echo esc_attr($risk_level); ?>" 
                data-phone="<?php echo esc_attr($normalized_phone); ?>" 
                data-name="<?php echo esc_attr($customer_name); ?>"
                title="<?php esc_attr_e('Click to view full Courier Delivery Intelligence', 'advance-fake-order-protector'); ?>">
            <div class="afop-mini-chart-ring">
                <svg viewBox="0 0 36 36" class="afop-circular-chart">
                    <path class="afop-circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                    <path class="afop-circle-progress" stroke-dasharray="<?php echo $has_data ? $rate_num : '0'; ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                </svg>
                <span class="afop-mini-chart-percent"><?php echo $has_data ? ($rate_num . '%') : '<i class="fa-solid fa-chart-pie"></i>'; ?></span>
            </div>
            <div class="afop-mini-graph-info">
                <div class="afop-mini-graph-header">
                    <span class="afop-mini-rate-text"><?php echo $has_data ? sprintf(__('%d%% Delivery', 'advance-fake-order-protector'), $rate_num) : __('Courier Ratio', 'advance-fake-order-protector'); ?></span>
                    <span class="afop-mini-risk-tag tag-<?php echo esc_attr($risk_level); ?>"><?php echo esc_html($risk_label); ?></span>
                </div>
                <div class="afop-mini-bar-track">
                    <div class="afop-mini-bar-fill fill-<?php echo esc_attr($risk_level); ?>" style="width: <?php echo $has_data ? $rate_num : '0'; ?>%;"></div>
                </div>
                <div class="afop-mini-graph-sub">
                    <?php if ($has_data): ?>
                        <span><i class="fa-solid fa-check"></i> <?php echo intval($delivered); ?></span>
                        <span><i class="fa-solid fa-rotate-left"></i> <?php echo intval($returned); ?></span>
                        <span>(<?php echo intval($total_orders); ?>)</span>
                    <?php else: ?>
                        <span><i class="fa-solid fa-bolt"></i> <?php esc_html_e('Click to check', 'advance-fake-order-protector'); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </button>
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
        $is_any_blocked = $phone_blocked || $ip_blocked;

        $customer_orders = !empty($normalized_phone) ? AFOP_Repeat_Order::get_orders_by_phone($normalized_phone) : array();
        $order_count = count($customer_orders);
        ?>
        <div class="afop-security-actions-cell" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
            <button type="button" 
                    class="afop-security-popup-btn <?php echo $is_any_blocked ? 'is-blocked' : 'is-safe'; ?>" 
                    data-phone="<?php echo esc_attr($normalized_phone); ?>"
                    data-ip="<?php echo esc_attr($client_ip); ?>"
                    data-name="<?php echo esc_attr($customer_name); ?>"
                    data-phone-blocked="<?php echo $phone_blocked ? 'true' : 'false'; ?>"
                    data-ip-blocked="<?php echo $ip_blocked ? 'true' : 'false'; ?>"
                    data-order-count="<?php echo intval($order_count); ?>"
                    title="<?php esc_attr_e('Click to open Security & Blocklist Actions', 'advance-fake-order-protector'); ?>">
                <div class="afop-sec-btn-inner">
                    <div class="afop-sec-btn-main">
                        <i class="fa-solid <?php echo $is_any_blocked ? 'fa-ban' : 'fa-shield-halved'; ?>"></i>
                        <span><?php echo $is_any_blocked ? esc_html__('Blocked', 'advance-fake-order-protector') : esc_html__('Security Actions', 'advance-fake-order-protector'); ?></span>
                    </div>
                    <?php if (!empty($normalized_phone)): ?>
                        <div class="afop-sec-btn-sub">
                            <i class="fa-solid fa-phone"></i> <?php echo esc_html($normalized_phone); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </button>
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
     * Render Single Order Meta Box Content (Order Details Page)
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

        $has_any_api = AFOP_Courier_Checker::has_any_api_configured();
        $courier_stat = ($has_any_api && !empty($normalized_phone)) ? AFOP_Courier_Checker::get_quick_courier_stat($normalized_phone) : null;
        $has_data = !empty($courier_stat) && !empty($courier_stat['has_api']) && isset($courier_stat['delivery_rate']);
        $rate_num = $has_data ? round(floatval($courier_stat['delivery_rate'])) : 0;
        $total_orders = $has_data && isset($courier_stat['total_orders']) ? intval($courier_stat['total_orders']) : 0;
        $delivered = $has_data && isset($courier_stat['delivered']) ? intval($courier_stat['delivered']) : 0;
        $returned = $has_data && isset($courier_stat['returned']) ? intval($courier_stat['returned']) : 0;

        $risk_level = 'neutral';
        $risk_label = __('Check Ratio', 'advance-fake-order-protector');

        if ($has_data) {
            if (isset($courier_stat['risk_level'])) {
                $risk_level = $courier_stat['risk_level'];
            } elseif ($rate_num >= 75) {
                $risk_level = 'safe';
            } elseif ($rate_num >= 50) {
                $risk_level = 'medium';
            } else {
                $risk_level = 'high';
            }

            if ($risk_level === 'safe') {
                $risk_label = __('Safe Customer', 'advance-fake-order-protector');
            } elseif ($risk_level === 'medium') {
                $risk_label = __('Medium Risk', 'advance-fake-order-protector');
            } else {
                $risk_label = __('High Risk', 'advance-fake-order-protector');
            }
        }
        ?>
        <div class="afop-meta-box-wrap">
            <div class="afop-meta-row">
                <span class="afop-meta-label"><i class="fa-solid fa-phone"></i> <strong>Phone:</strong> <?php echo esc_html($phone ?: 'N/A'); ?></span>
                <?php if (!empty($normalized_phone)): ?>
                    <button type="button" 
                            class="afop-btn-box afop-toggle-block-btn <?php echo $phone_blocked ? 'is-blocked' : 'is-safe'; ?>" 
                            data-type="phone" 
                            data-value="<?php echo esc_attr($normalized_phone); ?>">
                        <span class="afop-btn-icon"><i class="fa-solid <?php echo $phone_blocked ? 'fa-ban' : 'fa-phone-slash'; ?>"></i></span>
                        <span class="afop-btn-text"><?php echo $phone_blocked ? 'Unblock Phone' : 'Block Phone'; ?></span>
                    </button>
                <?php endif; ?>
            </div>

            <div class="afop-meta-row">
                <span class="afop-meta-label"><i class="fa-solid fa-globe"></i> <strong>IP:</strong> <?php echo esc_html($client_ip ?: 'N/A'); ?></span>
                <?php if (!empty($client_ip)): ?>
                    <button type="button" 
                            class="afop-btn-box afop-toggle-block-btn <?php echo $ip_blocked ? 'is-blocked' : 'is-safe'; ?>" 
                            data-type="ip" 
                            data-value="<?php echo esc_attr($client_ip); ?>">
                        <span class="afop-btn-icon"><i class="fa-solid <?php echo $ip_blocked ? 'fa-ban' : 'fa-globe'; ?>"></i></span>
                        <span class="afop-btn-text"><?php echo $ip_blocked ? 'Unblock IP' : 'Block IP'; ?></span>
                    </button>
                <?php endif; ?>
            </div>

            <?php if (!$has_any_api): ?>
                <!-- No API Configured Warning Banner in Order Details -->
                <div class="afop-no-api-details-notice" style="margin-top: 12px; padding: 12px; background: #fffbebfb; border: 1px solid #fcd34d; border-radius: 8px; font-size: 12px; color: #92400e; line-height: 1.5;">
                    <div style="font-weight: 700; font-size: 12.5px; margin-bottom: 4px; display: flex; align-items: center; gap: 6px; color: #b45309;">
                        <i class="fa-solid fa-triangle-exclamation"></i> <?php esc_html_e('কুরিয়ার API কনফিগার করা নেই', 'advance-fake-order-protector'); ?>
                    </div>
                    <?php esc_html_e('আপনি কোনো API অ্যাড করেন নাই। কুরিয়ার ডেলিভারি রেশিও দেখতে সেটিংস থেকে কুরিয়ার API Key যুক্ত করুন।', 'advance-fake-order-protector'); ?>
                    <div style="margin-top: 8px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=afop-settings')); ?>" class="button button-secondary button-small" style="color: #2563eb; font-weight: 600;">
                            <i class="fa-solid fa-gear"></i> <?php esc_html_e('সেটিংস থেকে API অ্যাড করুন', 'advance-fake-order-protector'); ?>
                        </a>
                    </div>
                </div>
            <?php elseif (!empty($normalized_phone)): ?>
                <!-- Courier Ratio Card in Meta Box -->
                <button type="button" 
                        class="afop-courier-mini-graph afop-check-courier-btn risk-<?php echo esc_attr($risk_level); ?>" 
                        style="margin-top: 12px; width: 100%;"
                        data-phone="<?php echo esc_attr($normalized_phone); ?>" 
                        data-name="<?php echo esc_attr($customer_name); ?>"
                        title="<?php esc_attr_e('Click to open full Courier Intelligence report', 'advance-fake-order-protector'); ?>">
                    <div class="afop-mini-chart-ring">
                        <svg viewBox="0 0 36 36" class="afop-circular-chart">
                            <path class="afop-circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            <path class="afop-circle-progress" stroke-dasharray="<?php echo $has_data ? $rate_num : '0'; ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        </svg>
                        <span class="afop-mini-chart-percent"><?php echo $has_data ? ($rate_num . '%') : '<i class="fa-solid fa-chart-pie"></i>'; ?></span>
                    </div>
                    <div class="afop-mini-graph-info">
                        <div class="afop-mini-graph-header">
                            <span class="afop-mini-rate-text"><?php echo $has_data ? sprintf(__('%d%% Delivery Rate', 'advance-fake-order-protector'), $rate_num) : __('Check Delivery Ratio', 'advance-fake-order-protector'); ?></span>
                            <span class="afop-mini-risk-tag tag-<?php echo esc_attr($risk_level); ?>"><?php echo esc_html($risk_label); ?></span>
                        </div>
                        <div class="afop-mini-bar-track">
                            <div class="afop-mini-bar-fill fill-<?php echo esc_attr($risk_level); ?>" style="width: <?php echo $has_data ? $rate_num : '0'; ?>%;"></div>
                        </div>
                        <div class="afop-mini-graph-sub">
                            <?php if ($has_data): ?>
                                <span><i class="fa-solid fa-check"></i> <?php echo intval($delivered); ?> Deliv</span>
                                <span><i class="fa-solid fa-rotate-left"></i> <?php echo intval($returned); ?> Return</span>
                                <span>(<?php echo intval($total_orders); ?> Total)</span>
                            <?php else: ?>
                                <span><i class="fa-solid fa-bolt"></i> <?php esc_html_e('Click to check intelligence', 'advance-fake-order-protector'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </button>
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

                <!-- Provider Tabs -->
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
                    <button type="button" class="afop-courier-tab-btn" data-provider="pathao">
                        <i class="fa-solid fa-motorcycle"></i> Pathao
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

        <!-- Customer Store Orders List Modal -->
        <div id="afop-admin-customer-orders-modal" class="afop-admin-modal-overlay" style="display:none;">
            <div class="afop-admin-modal-box" style="max-width: 720px;">
                <div class="afop-admin-modal-header">
                    <div class="afop-modal-header-left">
                        <span class="afop-modal-badge" style="background: #e0e7ff; color: #3730a3;"><i class="fa-solid fa-store"></i> Store Order History</span>
                        <h2 id="afop-cust-orders-title"><?php esc_html_e('Customer Store Orders', 'advance-fake-order-protector'); ?></h2>
                        <span id="afop-cust-orders-phone-badge" class="afop-phone-pill"></span>
                    </div>
                    <button type="button" class="afop-admin-modal-close" id="afop-close-cust-orders-modal">&times;</button>
                </div>

                <div class="afop-admin-modal-body">
                    <!-- Loader -->
                    <div id="afop-cust-orders-loading" class="afop-loader-wrap" style="display:none;">
                        <div class="afop-spinner"></div>
                        <p><?php esc_html_e('অর্ডার হিস্ট্রি লোড হচ্ছে...', 'advance-fake-order-protector'); ?></p>
                    </div>

                    <!-- Content Container -->
                    <div id="afop-cust-orders-content" style="display:none;">
                        <div class="afop-ratio-grid" style="grid-template-columns: repeat(2, 1fr); margin-bottom: 15px;">
                            <div class="afop-card afop-metric-item total" style="padding: 12px 16px;">
                                <span class="afop-metric-val" id="afop-cust-orders-count-val">0</span>
                                <span class="afop-metric-lbl"><i class="fa-solid fa-boxes-packing"></i> Total Orders Placed</span>
                            </div>
                            <div class="afop-card afop-metric-item success" style="padding: 12px 16px;">
                                <span class="afop-metric-val" id="afop-cust-orders-spent-val">৳ 0</span>
                                <span class="afop-metric-lbl"><i class="fa-solid fa-sack-dollar"></i> Total Spent Amount</span>
                            </div>
                        </div>

                        <div class="afop-breakdown-section">
                            <table class="afop-courier-table">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Products Purchased</th>
                                        <th>Total</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="afop-cust-orders-tbody">
                                    <!-- Dynamic rows -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="afop-admin-modal-footer">
                    <button type="button" class="button" id="afop-modal-close-cust-orders-btn"><?php esc_html_e('Close', 'advance-fake-order-protector'); ?></button>
                </div>
            </div>
        </div>

        <!-- Security Actions Popup Modal -->
        <div id="afop-admin-security-modal" class="afop-admin-modal-overlay" style="display:none;">
            <div class="afop-admin-modal-box" style="max-width: 480px;">
                <div class="afop-admin-modal-header">
                    <div class="afop-modal-header-left">
                        <span class="afop-modal-badge" style="background: #f1f5f9; color: #0f172a;"><i class="fa-solid fa-shield-halved"></i> Security Controls</span>
                        <h2 id="afop-sec-modal-customer-name"><?php esc_html_e('Security Actions', 'advance-fake-order-protector'); ?></h2>
                    </div>
                    <button type="button" class="afop-admin-modal-close" id="afop-close-sec-modal">&times;</button>
                </div>

                <div class="afop-admin-modal-body" style="padding: 20px;">
                    <!-- Customer Phone Card -->
                    <div class="afop-sec-card" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-bottom: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.02);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <div style="font-size: 13px; font-weight: 700; color: #0f172a;">
                                <i class="fa-solid fa-phone" style="color: #2563eb; margin-right: 6px;"></i> <?php esc_html_e('Phone Number', 'advance-fake-order-protector'); ?>
                            </div>
                            <span id="afop-sec-modal-phone-status" class="afop-badge-status status-unblocked">Safe</span>
                        </div>
                        
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-bottom: 12px;">
                            <a href="#" id="afop-sec-modal-phone-link" class="afop-phone-pill-link" style="font-size: 13px; padding: 5px 10px;" target="_blank">
                                <i class="fa-solid fa-phone"></i> <strong id="afop-sec-modal-phone-val">017XXXXXXXX</strong>
                            </a>

                            <button type="button" 
                                    id="afop-sec-modal-orders-btn"
                                    class="afop-customer-orders-btn" 
                                    style="font-size: 12px; padding: 5px 10px;"
                                    title="<?php esc_attr_e('View customer store order history', 'advance-fake-order-protector'); ?>">
                                <i class="fa-solid fa-boxes-packing"></i> 
                                <span id="afop-sec-modal-orders-count">0 Orders</span>
                            </button>
                        </div>

                        <div>
                            <button type="button" 
                                    id="afop-sec-modal-toggle-phone-btn"
                                    class="button afop-toggle-block-btn is-safe" 
                                    style="width: 100%; justify-content: center; height: 34px; font-weight: 600;"
                                    data-type="phone" 
                                    data-value="">
                                <span class="afop-btn-icon"><i class="fa-solid fa-phone-slash"></i></span>
                                <span class="afop-btn-text">Block Phone Number</span>
                            </button>
                        </div>
                    </div>

                    <!-- Customer IP Card -->
                    <div class="afop-sec-card" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.02);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <div style="font-size: 13px; font-weight: 700; color: #0f172a;">
                                <i class="fa-solid fa-globe" style="color: #0284c7; margin-right: 6px;"></i> <?php esc_html_e('IP Address', 'advance-fake-order-protector'); ?>
                            </div>
                            <span id="afop-sec-modal-ip-status" class="afop-badge-status status-unblocked">Safe</span>
                        </div>

                        <div style="margin-bottom: 12px;">
                            <span class="afop-ip-pill-link" style="font-size: 13px; padding: 5px 10px;">
                                <i class="fa-solid fa-globe"></i> <strong id="afop-sec-modal-ip-val">103.xxx.xxx.xxx</strong>
                            </span>
                        </div>

                        <div>
                            <button type="button" 
                                    id="afop-sec-modal-toggle-ip-btn"
                                    class="button afop-toggle-block-btn is-safe" 
                                    style="width: 100%; justify-content: center; height: 34px; font-weight: 600;"
                                    data-type="ip" 
                                    data-value="">
                                <span class="afop-btn-icon"><i class="fa-solid fa-globe"></i></span>
                                <span class="afop-btn-text">Block IP Address</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="afop-admin-modal-footer">
                    <button type="button" class="button" id="afop-close-sec-modal-footer"><?php esc_html_e('Close', 'advance-fake-order-protector'); ?></button>
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
            'afop_repeat_whatsapp_btn'      => 'text',
            'afop_repeat_whatsapp_msg'      => 'textarea',
            'afop_enable_incomplete_capture'=> 'yes_no',
            'afop_courier_provider'         => 'text',
            'afop_bdcourier_api_key'        => 'text',
            'afop_steadfast_api_key'        => 'text',
            'afop_steadfast_secret_key'     => 'text',
            'afop_fraudbd_api_key'          => 'text',
            'afop_pathao_base_url'          => 'text',
            'afop_pathao_client_id'         => 'text',
            'afop_pathao_client_secret'     => 'text',
            'afop_pathao_username'          => 'text',
            'afop_pathao_password'          => 'text',
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
     * Admin AJAX to fetch customer store orders history by phone
     */
    public function ajax_get_customer_orders() {
        check_ajax_referer('afop_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized.'));
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        if (empty($phone)) {
            wp_send_json_error(array('message' => 'Phone number is required.'));
        }

        $orders = AFOP_Repeat_Order::get_orders_by_phone($phone);
        $total_spent = 0;
        foreach ($orders as $o) {
            $total_spent += floatval($o['raw_total']);
        }

        wp_send_json_success(array(
            'phone'            => AFOP_Validator::normalize_phone($phone),
            'order_count'      => count($orders),
            'formatted_spent'  => wc_price($total_spent),
            'orders'           => $orders
        ));
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
                    <h1><i class="fa-solid fa-shield-halved"></i> SARKAR IT Fake protection</h1>
                    <p>WooCommerce Fake Order Defense, Bangladeshi Phone Verification, Repeat Order Protection with WhatsApp & Multi-Courier Ratio Analysis</p>
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
                                        একই কাস্টমার নির্দিষ্ট সময়ের মধ্যে একই প্রোডাক্ট ২য় বার অর্ডার করতে গেলে সরাসরি কনফার্ম না করে হোয়াটসঅ্যাপে যোগাযোগের পপআপ দেখাবে।
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
                                    <input type="text" name="afop_repeat_order_title" value="<?php echo esc_attr(get_option('afop_repeat_order_title', 'পুনরায় অর্ডার সংক্রান্ত তথ্য')); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Repeat Order Warning Message</th>
                                <td>
                                    <textarea name="afop_repeat_order_msg" rows="3" class="large-text"><?php echo esc_textarea(get_option('afop_repeat_order_msg', 'আপনি {minutes} মিনিট আগে এই প্রোডাক্টটি অর্ডার করেছিলেন। পুনরায় একই প্রোডাক্ট অর্ডার করতে চাইলে অনুগ্রহ করে আমাদের হোয়াটসঅ্যাপে যোগাযোগ করুন।')); ?></textarea>
                                    <p class="description">ব্যবহার করুন <code>{minutes}</code> কত মিনিট আগে অর্ডার করেছিল তা স্বয়ংক্রিয়ভাবে দেখাতে।</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">WhatsApp Button Text</th>
                                <td>
                                    <input type="text" name="afop_repeat_whatsapp_btn" value="<?php echo esc_attr(get_option('afop_repeat_whatsapp_btn', 'হোয়াটসঅ্যাপে অর্ডার করুন')); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">WhatsApp Pre-filled Message</th>
                                <td>
                                    <textarea name="afop_repeat_whatsapp_msg" rows="2" class="large-text"><?php echo esc_textarea(get_option('afop_repeat_whatsapp_msg', 'Hello Support, I placed an order recently and would like to order the same product again. Please assist me.')); ?></textarea>
                                </td>
                            </tr>
                        </table>
                    </div>

                <?php elseif ($active_tab === 'courier'): ?>
                    <div class="afop-settings-section">
                        <h3><i class="fa-solid fa-truck-fast"></i> Courier API & Fraud Ratio Configuration</h3>
                        <p class="description" style="margin-bottom: 20px;">এখানে প্রতিটি কুরিয়ার সার্ভিসের API সেটিংস আলাদা আলাদা সেকশনে সুন্দরভাবে সাজানো হয়েছে। আপনার ব্যবহৃত কুরিয়ারের ক্রেডেনশিয়াল টাইপ করে সেভ করুন।</p>

                        <!-- 1. General & Default Provider -->
                        <div class="afop-courier-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 20px; margin-bottom: 20px;">
                            <h4 style="margin: 0 0 15px; font-size: 15px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-sliders" style="color: #6366f1;"></i> Primary Default Courier Provider
                            </h4>
                            <table class="form-table" style="margin: 0;">
                                <tr>
                                    <th scope="row">Default Provider</th>
                                    <td>
                                        <select name="afop_courier_provider" id="afop_courier_provider" class="regular-text">
                                            <option value="bdcourier" <?php selected(get_option('afop_courier_provider', 'bdcourier'), 'bdcourier'); ?>>BD Courier API (api.bdcourier.com)</option>
                                            <option value="steadfast" <?php selected(get_option('afop_courier_provider', 'bdcourier'), 'steadfast'); ?>>Steadfast Courier API</option>
                                            <option value="fraudbd" <?php selected(get_option('afop_courier_provider', 'bdcourier'), 'fraudbd'); ?>>FraudBD API (fraudbd.com)</option>
                                            <option value="pathao" <?php selected(get_option('afop_courier_provider', 'bdcourier'), 'pathao'); ?>>Pathao Courier API (pathao.com)</option>
                                        </select>
                                        <p class="description">অর্ডার টেবিল ও মোডালে ডিফল্টভাবে যে কুরিয়ারের ডাটা দেখাবে।</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Cache Duration (Hours)</th>
                                    <td>
                                        <input type="number" name="afop_courier_cache_hours" value="<?php echo esc_attr(get_option('afop_courier_cache_hours', 24)); ?>" class="small-text" min="1" max="168"> ঘণ্টা
                                        <p class="description">কুরিয়ার হিস্ট্রি ক্যাশে থাকবে যেন প্রতিবার নতুন API কল করতে না হয় (ডিফল্ট: ২৪ ঘণ্টা)।</p>
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

                        <!-- 2. Pathao Courier API -->
                        <div class="afop-courier-card" style="background: #ffffff; border: 1px solid #cbd5e1; border-left: 4px solid #ef4444; border-radius: 10px; padding: 18px 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                            <h4 style="margin: 0 0 15px; font-size: 15px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-motorcycle" style="color: #ef4444;"></i> Pathao Courier Merchant API Settings
                            </h4>
                            <table class="form-table" style="margin: 0;">
                                <tr>
                                    <th scope="row">Pathao Environment</th>
                                    <td>
                                        <select name="afop_pathao_base_url" id="afop_pathao_base_url" class="regular-text">
                                            <option value="https://courier-api-sandbox.pathao.com" <?php selected(get_option('afop_pathao_base_url', 'https://courier-api-sandbox.pathao.com'), 'https://courier-api-sandbox.pathao.com'); ?>>Sandbox / Test Environment (https://courier-api-sandbox.pathao.com)</option>
                                            <option value="https://api-hermes.pathao.com" <?php selected(get_option('afop_pathao_base_url', 'https://courier-api-sandbox.pathao.com'), 'https://api-hermes.pathao.com'); ?>>Production / Live Environment (https://api-hermes.pathao.com)</option>
                                        </select>
                                        <p class="description">টেস্টিং এর সময় Sandbox এবং লাইভ ব্যবহারের সময় Production সিলেক্ট করুন।</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Pathao Client ID</th>
                                    <td>
                                        <input type="text" name="afop_pathao_client_id" value="<?php echo esc_attr(get_option('afop_pathao_client_id', '')); ?>" class="large-text" placeholder="Pathao Merchant API Client ID">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Pathao Client Secret</th>
                                    <td>
                                        <input type="password" name="afop_pathao_client_secret" value="<?php echo esc_attr(get_option('afop_pathao_client_secret', '')); ?>" class="large-text" placeholder="Pathao Merchant API Client Secret">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Pathao Login Email (Username)</th>
                                    <td>
                                        <input type="email" name="afop_pathao_username" value="<?php echo esc_attr(get_option('afop_pathao_username', '')); ?>" class="large-text" placeholder="Pathao Merchant Email">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Pathao Login Password</th>
                                    <td>
                                        <input type="password" name="afop_pathao_password" value="<?php echo esc_attr(get_option('afop_pathao_password', '')); ?>" class="large-text" placeholder="Pathao Merchant Password">
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- 3. Steadfast Courier API -->
                        <div class="afop-courier-card" style="background: #ffffff; border: 1px solid #cbd5e1; border-left: 4px solid #f59e0b; border-radius: 10px; padding: 18px 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                            <h4 style="margin: 0 0 15px; font-size: 15px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-bolt" style="color: #f59e0b;"></i> Steadfast Courier API Settings
                            </h4>
                            <table class="form-table" style="margin: 0;">
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
                            </table>
                        </div>

                        <!-- 4. FraudBD API -->
                        <div class="afop-courier-card" style="background: #ffffff; border: 1px solid #cbd5e1; border-left: 4px solid #3b82f6; border-radius: 10px; padding: 18px 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                            <h4 style="margin: 0 0 15px; font-size: 15px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-shield-halved" style="color: #3b82f6;"></i> FraudBD API Settings (fraudbd.com)
                            </h4>
                            <table class="form-table" style="margin: 0;">
                                <tr>
                                    <th scope="row">FraudBD API Key</th>
                                    <td>
                                        <input type="password" name="afop_fraudbd_api_key" value="<?php echo esc_attr(get_option('afop_fraudbd_api_key', '')); ?>" class="large-text" placeholder="FraudBD Account থেকে প্রাপ্ত API Key">
                                        <p class="description"><a href="https://fraudbd.com" target="_blank">fraudbd.com</a> থেকে আপনার API Key সংগ্রহ করুন (অথবা টেস্ট করার জন্য Sandbox Key ব্যবহার করতে পারেন)।</p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- 5. BD Courier API -->
                        <div class="afop-courier-card" style="background: #ffffff; border: 1px solid #cbd5e1; border-left: 4px solid #10b981; border-radius: 10px; padding: 18px 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                            <h4 style="margin: 0 0 15px; font-size: 15px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-truck-fast" style="color: #10b981;"></i> BD Courier API Settings (api.bdcourier.com)
                            </h4>
                            <table class="form-table" style="margin: 0;">
                                <tr>
                                    <th scope="row">BD Courier API Key</th>
                                    <td>
                                        <input type="password" name="afop_bdcourier_api_key" value="<?php echo esc_attr(get_option('afop_bdcourier_api_key', '')); ?>" class="large-text" placeholder="BD Courier থেকে প্রাপ্ত API Key দিন">
                                        <p class="description">bdcourier.com থেকে আপনার API Key সংগ্রহ করুন।</p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <p class="submit">
                    <input type="submit" name="afop_save_settings" id="submit" class="button button-primary button-large" value="<?php esc_attr_e('Save Changes', 'advance-fake-order-protector'); ?>">
                </p>
            </form>

            <!-- SARKAR IT Copyright Footer -->
            <div class="afop-copyright-card">
                <div class="afop-copyright-left">
                    <div class="afop-copyright-title">
                        <i class="fa-solid fa-shield-halved"></i> <strong><a href="https://www.sarkarit.com/" target="_blank">SARKAR IT</a></strong> Fake protection
                    </div>
                    <p class="afop-copyright-address"><i class="fa-solid fa-location-dot"></i> Flat No: 4A, House, 9 Main Rd, Dhaka 1207</p>
                </div>
                <div class="afop-copyright-right">
                    <p><i class="fa-brands fa-facebook"></i> Call for Facebook: <a href="tel:+8801785552264">+88 01785552264</a></p>
                    <p><i class="fa-solid fa-phone"></i> Call for Web: <a href="tel:+8801789363695">+88 01789363695</a></p>
                    <p><i class="fa-solid fa-envelope"></i> Email: <a href="mailto:info@sarkarit.com">info@sarkarit.com</a></p>
                    <p class="afop-copyright-text"><small>&copy; <?php echo date('Y'); ?> <a href="https://www.sarkarit.com/" target="_blank">SARKAR IT</a>. All Rights Reserved.</small></p>
                </div>
            </div>
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
