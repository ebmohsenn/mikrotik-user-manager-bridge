<?php

require_once __DIR__ . '/../../vendor/autoload.php';
foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400] as $c => $v) {
    if (!defined($c)) {
        define($c, $v);
    }
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
require_once __DIR__ . '/../../vendor/php-stubs/wordpress-stubs/wordpress-stubs.php';

// Minimal WordPress shims for static analysis.
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {}
}
if (!function_exists('current_time')) {
    function current_time($type) { return date('Y-m-d H:i:s'); }
}
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return $text; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return $text; }
}
if (!function_exists('esc_js')) {
    function esc_js($text) { return $text; }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '', $scheme = 'https') { return 'https://example.com/' . ltrim($path, '/'); }
}
if (!function_exists('delete_option')) {
    function delete_option($option) { return true; }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook) {}
}

// WooCommerce function stubs for PHPStan
if (!function_exists('wc_get_order')) {
    /**
     * @param int|WC_Order $order_id
     * @return WC_Order|false
     */
    function wc_get_order($order_id) {
        return false;
    }
}

if (!function_exists('wc_get_product')) {
    /**
     * @param int|WC_Product $product_id
     * @return WC_Product|false
     */
    function wc_get_product($product_id) {
        return false;
    }
}

if (!function_exists('wcs_create_subscription')) {
    /**
     * @param array $args
     * @return WC_Subscription|WP_Error
     */
    function wcs_create_subscription($args = array()) {
        return new WP_Error();
    }
}

if (!function_exists('wcs_get_subscription')) {
    /**
     * @param int $subscription_id
     * @return WC_Subscription|false
     */
    function wcs_get_subscription($subscription_id) {
        return false;
    }
}

if (!function_exists('wcs_get_subscriptions_for_order')) {
    /**
     * @param int|WC_Order $order_id
     * @return WC_Subscription[]
     */
    function wcs_get_subscriptions_for_order($order_id) {
        return array();
    }
}

// WooCommerce class stubs for PHPStan
if (!class_exists('WC_Order')) {
    class WC_Order {
        public function get_id() { return 0; }
        public function get_user_id() { return 0; }
        public function get_billing_email() { return ''; }
        public function get_items() { return array(); }
    }
}

if (!class_exists('WC_Product')) {
    class WC_Product {
        public function get_id() { return 0; }
        public function get_name() { return ''; }
    }
}

if (!class_exists('WC_Subscription')) {
    class WC_Subscription extends WC_Order {
        public function add_product($product, $quantity = 1, $args = array()) {}
        public function set_date($date_type, $date = null) {}
        public function calculate_totals() {}
        public function save() { return 0; }
        public function get_end_date($format = 'mysql') { return ''; }
    }
}
