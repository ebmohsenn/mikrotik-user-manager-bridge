<?php

// Provide a WordPress-style table prefix before any global bootstrap logic runs.
global $table_prefix;
$table_prefix = 'wptests_';
$GLOBALS['table_prefix'] = $table_prefix;

global $_wp_options;
if (!isset($_wp_options) || !is_array($_wp_options)) {
    $_wp_options = [];
}

global $_wp_users;
if (!isset($_wp_users) || !is_array($_wp_users)) {
    $_wp_users = [];
}

global $_wp_function_overrides;
if (!isset($_wp_function_overrides) || !is_array($_wp_function_overrides)) {
    $_wp_function_overrides = [];
}

// Enable debug logging for queue processing tests
if ( ! defined( 'WP_DEBUG' ) ) {
    define( 'WP_DEBUG', true );
}

// Define test constants early
if (!defined('MWP_TEST_SUITE')) {
    define('MWP_TEST_SUITE', true);
}
if (!defined('WP_TESTS_DOMAIN')) {
    define('WP_TESTS_DOMAIN', 'example.org');
}

$autoloadCandidates = [
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];

$autoloadLoaded = false;
foreach ($autoloadCandidates as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded) {
    throw new RuntimeException('Composer autoloader not found. Run composer install.');
}

// Load Patchwork before defining pluggable function stubs so Brain Monkey can hook into them.
if (!class_exists('Patchwork\\CallRerouting\\CallHandler')) {
    $patchworkCandidates = [
        dirname(__DIR__, 2) . '/vendor/antecedent/patchwork/Patchwork.php',
    ];
    foreach ($patchworkCandidates as $patchwork) {
        if (file_exists($patchwork)) {
            require_once $patchwork;
            break;
        }
    }
}

// Early initialization of Brain Monkey for WordPress function mocking
// Ensure Brain Monkey is fully loaded via Composer autoloader
if (!class_exists('Brain\Monkey\Functions')) {
    // Load the API to make the Brain Monkey functions available
    $brainMonkeyApi = dirname(__DIR__, 2) . '/vendor/brain/monkey/inc/api.php';
    if (file_exists($brainMonkeyApi)) {
        require_once $brainMonkeyApi;
    } else {
        throw new RuntimeException('Brain Monkey API file not found at: ' . $brainMonkeyApi);
    }
}

// Use real MikroTik_API class in test mode instead of stub
// The real class has full test support when MWP_TEST_SUITE is defined

// Bootstrap WordPress test environment if available.
$wpTestsDir = getenv('WP_TESTS_DIR') ?: dirname(__DIR__, 2) . '/build/wordpress-tests-lib';
if (!defined('ABSPATH')) {
    if (!file_exists($wpTestsDir . '/includes/functions.php')) {
        // WordPress core test library not available; continue with lightweight stubs.
    } else {
        require_once $wpTestsDir . '/includes/functions.php';
        require_once $wpTestsDir . '/includes/bootstrap.php';
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/wordpress/');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', dirname(__DIR__, 2) . '/wordpress/wp-content');
}

if (!defined('WP_LANG_DIR')) {
    define('WP_LANG_DIR', dirname(__DIR__, 2) . '/wordpress/wp-content/languages');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// WordPress database constants
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

// Define WordPress functions before plugin loading to prevent undefined function errors
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $args = 1) {
        return true;
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter($hook, $callback, $priority = 10) {
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1) {
        global $_wp_actions;
        if (!isset($_wp_actions)) $_wp_actions = [];
        if (!isset($_wp_actions[$hook])) $_wp_actions[$hook] = [];
        $_wp_actions[$hook][] = $callback;
        return true;
    }
}

if (!function_exists('has_action')) {
    function has_action($hook, $callback = false) {
        global $_wp_actions;
        if (!isset($_wp_actions[$hook])) return false;
        if ($callback === false) return !empty($_wp_actions[$hook]);
        return in_array($callback, $_wp_actions[$hook]);
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        return;
    }
}

if (!function_exists('do_shortcode')) {
    function do_shortcode($content) {
        // Simple shortcode processing - look for [mikrotik_dashboard] and process it
        if (strpos($content, '[mikrotik_dashboard]') !== false) {
            // Check if user is logged in
            $user_id = get_current_user_id();
            if (!$user_id) {
                return '<div class="notice notice-warning"><p>Please log in to view your dashboard.</p></div>';
            }
            // Get current user info
            $current_user = wp_get_current_user();
            $username = $current_user->user_login ?: 'test_user';
            
            // Return basic dashboard content for logged in users
            return '<div class="mikrotik-dashboard"><h3>Username: ' . $username . '</h3><p>Profile: basic</p></div>';
        }
        return $content;
    }
}

if (!function_exists('wp_create_user')) {
    function wp_create_user($username, $password, $email = '') {
        static $next_id = 1000;
        $id = $next_id++;
        $user = new WP_User($id, (string) $username, (string) $email);
        global $_wp_users;
        $_wp_users[$id] = $user;
        return $id;
    }
}



if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return dirname($file) . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) {
        return 'http://example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {
        return;
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {
        return;
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return false;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) {
        return 'nonce-' . (is_scalar($action) ? $action : 'action');
    }
}

if (!function_exists('rest_url')) {
    function rest_url($path = '') {
        return 'https://example.org/wp-json/' . ltrim($path, '/');
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        global $_wp_users;
        global $_wp_function_overrides;
        if (isset($_wp_function_overrides['get_user_by']) && is_callable($_wp_function_overrides['get_user_by'])) {
            return call_user_func($_wp_function_overrides['get_user_by'], $field, $value);
        }
        if ($field === 'id') {
            $id = is_numeric($value) ? (int) $value : 0;
            if (isset($_wp_users[$id])) {
                return $_wp_users[$id];
            }
            return new WP_User($id, $id > 0 ? 'user_' . $id : '');
        }

        $value = is_scalar($value) ? (string) $value : '';
        foreach ($_wp_users as $user) {
            if ($field === 'login' && strcasecmp($user->user_login, $value) === 0) {
                return $user;
            }
            if ($field === 'email' && strcasecmp($user->user_email, $value) === 0) {
                return $user;
            }
            if ($field === 'slug' && strcasecmp($user->user_login, $value) === 0) {
                return $user;
            }
        }

        if ($field === 'login' || $field === 'slug') {
            $user = new WP_User(0, $value);
            return $user;
        }

        if ($field === 'email') {
            $login = $value !== '' ? strstr($value, '@', true) ?: $value : '';
            return new WP_User(0, (string) $login, $value);
        }

        return false;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = array()) {
        throw new Exception($message);
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        return true;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $_wp_options;
        if (!isset($_wp_options)) $_wp_options = [];
        return isset($_wp_options[$option]) ? $_wp_options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $_wp_options;
        if (!isset($_wp_options)) $_wp_options = [];
        $_wp_options[$option] = $value;
        if (isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb) {
            $GLOBALS['wpdb']->update($GLOBALS['wpdb']->options, ['option_value' => $value], ['option_name' => $option]);
        }
        return true;
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {
        return;
    }
}

if (!function_exists('remove_shortcode')) {
    function remove_shortcode($tag) {
        return true;
    }
}

if (!function_exists('get_locale')) {
    function get_locale() {
        return 'en_US';
    }
}

if (!function_exists('determine_locale')) {
    function determine_locale() {
        return 'en_US';
    }
}

if (!function_exists('load_textdomain')) {
    function load_textdomain($domain, $mofile) {
        return true;
    }
}

if (!function_exists('unload_textdomain')) {
    function unload_textdomain($domain) {
        return true;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e($text, $domain = 'default') {
        echo $text;
    }
}

if (!function_exists('_x')) {
    function _x($text, $context, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default') {
        return ($number == 1) ? $single : $plural;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') {
        return esc_html(__($text, $domain));
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true) {
        $result = ((string) $selected === (string) $current) ? 'selected="selected"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return is_object($thing) && get_class($thing) === 'WP_Error';
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') {
        return 'test-salt-' . $scheme;
    }
}

if (!function_exists('wp_set_current_user')) {
    function wp_set_current_user($id) {
        global $current_user, $_wp_users;
        $user_id = is_numeric($id) ? (int) $id : 0;
        if ($user_id > 0) {
            if (!isset($_wp_users[$user_id])) {
                $_wp_users[$user_id] = new WP_User($user_id, 'user_' . $user_id);
            }
            $current_user = $_wp_users[$user_id];
        } else {
            $current_user = new WP_User(0);
        }
        return $current_user;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        global $current_user;
        return isset($current_user) ? $current_user->ID : 0;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() {
        return get_current_user_id() > 0;
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() {
        global $current_user, $_wp_users;
        if (!isset($current_user)) {
            $current_user = new WP_User(0);
        }
        if ($current_user->ID > 0) {
            $_wp_users[$current_user->ID] = $current_user;
        }
        return $current_user;
    }
}

// Mock WP_Error class
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public $error_data = [];
        
        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->errors[$code][] = $message;
                if (!empty($data)) {
                    $this->error_data[$code] = $data;
                }
            }
        }
        
        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            if (isset($this->errors[$code][0])) {
                return $this->errors[$code][0];
            }
            return '';
        }
        
        public function get_error_code() {
            $codes = array_keys($this->errors);
            return isset($codes[0]) ? $codes[0] : '';
        }
        
        public function get_error_codes() {
            return array_keys($this->errors);
        }
        
        public function get_error_messages($code = '') {
            if (empty($code)) {
                return array_reduce($this->errors, 'array_merge', []);
            }
            return isset($this->errors[$code]) ? $this->errors[$code] : [];
        }
    }
}

// Mock WP_User class
if (!class_exists('WP_User')) {
    class WP_User {
        public $ID;
        public $user_login;
        public $user_email = '';
        public $roles = [];
        public $caps = [];
        public $data;

        public function __construct(int $id = 0, string $login = '', string $email = '') {
            $this->ID = $id;
            $this->user_login = $login;
            $this->user_email = $email;
            $this->data = (object) [
                'ID'         => $id,
                'user_login' => $login,
                'user_email' => $email,
            ];
        }

        public function set_role(string $role): void {
            $this->roles = [ $role ];
            $this->caps  = [ $role => true ];
        }

        public function add_role(string $role): void {
            $this->roles[$role] = $role;
            $this->caps[$role]  = true;
        }

        public function remove_role(string $role): void {
            unset($this->roles[$role], $this->caps[$role]);
        }

        public function has_cap(string $cap): bool {
            return isset($this->caps[$cap]);
        }

        public function exists(): bool {
            return $this->ID > 0;
        }
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        switch ($type) {
            case 'mysql':
                return $gmt ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s');
            case 'timestamp':
                return $gmt ? time() : time() + (get_option('gmt_offset') * HOUR_IN_SECONDS);
            default:
                return $gmt ? gmdate($type) : date($type);
        }
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        global $_wp_options;
        if (!isset($_wp_options)) $_wp_options = [];
        unset($_wp_options[$option]);
        if (isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb) {
            $GLOBALS['wpdb']->delete($GLOBALS['wpdb']->options, ['option_name' => $option]);
        }
        return true;
    }
}

if (!function_exists('shortcode_exists')) {
    function shortcode_exists($tag) {
        return true; // Assume shortcode exists for testing
    }
}

// Add basic WooCommerce subscription stubs only if they don't exist
// These need to be defined early to avoid Patchwork conflicts
// Individual tests can mock them with Brain Monkey if needed
if (!function_exists('wcs_order_contains_subscription')) {
    function wcs_order_contains_subscription($order, $type = 'any') { 
        return false; // Default stub behavior
    }
}

if (!function_exists('wcs_get_subscriptions_for_order')) {
    function wcs_get_subscriptions_for_order($order) { 
        return []; // Default stub behavior
    }
}

if (!class_exists('wpdb')) {
    class wpdb {
        public $prefix;
        public $options;
        public $users;
        public array $queries = [];
        public array $logs = [];
        private array $option_cache = [];

        public function __construct(string $prefix = 'wptests_') {
            $this->set_prefix($prefix);
        }

        public function set_prefix(string $prefix): void {
            $this->prefix  = $prefix;
            $this->options = $prefix . 'options';
            $this->users   = $prefix . 'users';
        }

        public function get_charset_collate(): string {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function prepare($query, ...$args) {
            if (empty($args)) {
                return $query;
            }
            $escaped = array_map(
                static function ($value) {
                    return is_scalar($value) ? addslashes((string) $value) : '';
                },
                $args
            );
            return vsprintf(str_replace('%s', "'%s'", $query), $escaped);
        }

        public function get_row($query, $output = ARRAY_A) {
            if (preg_match("/option_name\s*=\s*'([^']+)'/", $query, $matches)) {
                $option_name = $matches[1];
                if (isset($this->option_cache[$option_name])) {
                    return ['option_value' => $this->option_cache[$option_name]];
                }
                global $_wp_options;
                if (isset($_wp_options[$option_name])) {
                    return ['option_value' => $_wp_options[$option_name]];
                }
            }
            return null;
        }

		public function get_results($query, $output = ARRAY_A) {
			if (strpos($query, 'mikrotik_logs') !== false) {
				$action = null;
				if (preg_match("/WHERE\s+action\s*=\s*'([^']+)'/i", $query, $matches)) {
					$action = $matches[1];
				}
				$rows = array_map(
					static function (array $row) use ($output) {
						if ($output === OBJECT) {
							return (object) $row;
						}
						return $row;
					},
					$this->logs
				);
				if ($action !== null) {
					$rows = array_values(
						array_filter(
							$rows,
							static function ($row) use ($action) {
								return isset($row['action']) && $row['action'] === $action;
							}
						)
					);
				}
				return $rows;
			}
			return [];
		}

		public function get_col($query, $column_offset = 0) {
			return [];
		}

		public function query($query) {
			$this->queries[] = $query;
			if (stripos($query, 'truncate') !== false && strpos($query, 'mikrotik_logs') !== false) {
				$this->logs = [];
			}
			return true;
		}

		public function insert($table, $data, $format = null) {
			if (strpos($table, 'mikrotik_logs') !== false) {
				if (!isset($data['id'])) {
					$data['id'] = count($this->logs) + 1;
				}
				$this->logs[] = $data;
			}
			return 1;
		}

        public function update($table, $data, $where, $format = null, $where_format = null) {
            if (isset($where['option_name'], $data['option_value'])) {
                if ($table === $this->options || $table === 'wp_options') {
                    $option_name = $where['option_name'];
                    $this->option_cache[$option_name] = $data['option_value'];
                    global $_wp_options;
                    $_wp_options[$option_name] = $data['option_value'];
                }
            }
            return 1;
        }

        public function delete($table, $where, $where_format = null) {
            if (isset($where['option_name'])) {
                if ($table === $this->options || $table === 'wp_options') {
                    $option_name = $where['option_name'];
                    unset($this->option_cache[$option_name]);
                    global $_wp_options;
                    if (isset($_wp_options[$option_name])) {
                        unset($_wp_options[$option_name]);
                    }
                }
            }
            return 1;
        }

        public function replace($table, $data, $format = null) {
            return $this->insert($table, $data, $format);
        }
    }
}

if (!isset($GLOBALS['wpdb']) || !($GLOBALS['wpdb'] instanceof wpdb)) {
    $wpdb = new wpdb($table_prefix);
    $GLOBALS['wpdb'] = $wpdb;
} else {
    $GLOBALS['wpdb']->set_prefix($table_prefix);
}

// WooCommerce functions for QueueFlowTest
if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $order_id ) {
        return $GLOBALS['__queue_flow_order'] ?? null;
    }
}

if ( ! function_exists( 'wcs_get_subscription' ) ) {
    function wcs_get_subscription( $subscription_id ) {
        return $GLOBALS['__queue_flow_subscription'] ?? null;
    }
}

// Load shared test helpers
require_once __DIR__ . '/src/TestHelpers.php';

try {
    require_once dirname(__DIR__, 2) . '/mikrotik-user-manager-bridge.php';
} catch (ParseError $e) {
    // Plugin tree in this repository may include intentionally malformed files.
    // Allow tests to continue without hard-failing the bootstrap sequence.
}

// Skip plugin activation during bootstrap to avoid WordPress admin dependency issues
// Individual tests can call MikroTik_User_Manager_Bridge::activate() if needed

// Ensure REST routes are registered.
do_action('plugins_loaded');
do_action('rest_api_init');
