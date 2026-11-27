<?php
/**
 * Plugin Name: MikroTik User Manager Bridge
 * Plugin URI: https://github.com/mohsenebrahimizadeh-ai/mikrotik-user-manager-bridge
 * Description: Bridge MikroTik RouterOS User Manager with WordPress through the RouterOS API (ports 8728/8729) or REST API. Synchronizes router users and profiles with WordPress, provisions users from WooCommerce orders, provides REST APIs and a React admin dashboard, and includes optional reseller/affiliate support.
 * Version: 2.1.1
 * Author: Mohsen Ebrahimizadeh
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mikrotik-user-manager-bridge
 * Domain Path: /languages
 * Requires at least: 6.5
 * Tested up to: 6.6
 * Requires PHP: 8.1
 * Network: false
 *
 * Copyright (C) 2026 Mohsen Ebrahimizadeh
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <https://www.gnu.org/licenses/>.
 *
 * @package MikroTik_User_Manager_Bridge
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use MUMB\Services\SettingsService;

defined( 'MUMB_VERSION' ) || define( 'MUMB_VERSION', '2.1.1' );
defined( 'MUMB_FILE' ) || define( 'MUMB_FILE', __FILE__ );
defined( 'MUMB_DIR' ) || define( 'MUMB_DIR', plugin_dir_path( __FILE__ ) );
defined( 'MUMB_URL' ) || define( 'MUMB_URL', plugin_dir_url( __FILE__ ) );

// Optional Composer autoloader (only present when the plugin is installed from source with `composer install`).
$autoload = __DIR__ . '/vendor/autoload.php';
$should_autoload = ! defined( 'MUMB_SKIP_COMPOSER_AUTOLOAD' ) || ! MUMB_SKIP_COMPOSER_AUTOLOAD;
if ( $should_autoload && file_exists( $autoload ) ) {
    require_once $autoload;
}

if ( ! class_exists( '\\MUMB\\Services\\SettingsService', false ) ) {
    $includes_dir = __DIR__ . '/includes';
    if ( is_dir( $includes_dir ) ) {
        // First, load all interface files to ensure they're available before classes
        $interface_files = [];
        $other_files = [];
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $includes_dir,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ( $iterator as $file ) {
            /** @var SplFileInfo $file */
            if ( $file->isFile() && $file->getExtension() === 'php' ) {
                $filename = $file->getFilename();
                if ( strpos( $filename, 'interface-' ) === 0 ) {
                    $interface_files[] = $file->getPathname();
                } else {
                    $other_files[] = $file->getPathname();
                }
            }
        }
        
        // Load interfaces first
        foreach ( $interface_files as $file ) {
            require_once $file;
        }
        
        // Then load all other files
        foreach ( $other_files as $file ) {
            require_once $file;
        }
    }
}

// Optional error reporting: if the Sentry PHP SDK is available (e.g. installed site-wide via Composer)
// and MUMB_SENTRY_DSN is defined in wp-config.php, plugin errors are reported to that Sentry project.
if (
    defined( 'MUMB_SENTRY_DSN' )
    && '' !== (string) MUMB_SENTRY_DSN
    && function_exists( '\\Sentry\\init' )
) {
    \Sentry\init([
        'dsn' => (string) MUMB_SENTRY_DSN,
        'release' => 'mikrotik-user-manager-bridge@' . MUMB_VERSION,
        'environment' => defined( 'WP_ENV' ) ? (string) WP_ENV : 'production',
    ]);
}

// Fallback autoloading for core plugin classes when Composer classmap is unavailable.
if ( ! class_exists( 'MUMB_REST', false ) && file_exists( __DIR__ . '/includes/class-rest.php' ) ) {
    require_once __DIR__ . '/includes/class-rest.php';
}

if ( ! class_exists( 'MWP_REST', false ) && file_exists( __DIR__ . '/includes/class-mwp-rest.php' ) ) {
    require_once __DIR__ . '/includes/class-mwp-rest.php';
}

if ( ! class_exists( 'MUMB_Affiliates', false ) && file_exists( __DIR__ . '/includes/class-affiliates.php' ) ) {
    require_once __DIR__ . '/includes/class-affiliates.php';
}

if ( ! class_exists( 'MUMB_Woo_Integration', false ) && file_exists( __DIR__ . '/includes/class-woocommerce-integration.php' ) ) {
    require_once __DIR__ . '/includes/class-woocommerce-integration.php';
}

// Load test stub for MikroTik_API if specified (for testing)
if ( defined( 'MWP_MIKROTIK_API_PATH' ) && file_exists( MWP_MIKROTIK_API_PATH ) && ! class_exists( 'MikroTik_API', false ) ) {
    require_once MWP_MIKROTIK_API_PATH;
}

// Load real MikroTik_API class only if not already loaded (including test stub)
if ( ! class_exists( 'MikroTik_API', false ) && file_exists( __DIR__ . '/includes/class-mikrotik-api.php' ) ) {
    require_once __DIR__ . '/includes/class-mikrotik-api.php';
}

if ( ! class_exists( 'MikroTik_User_Manager_Bridge', false ) ) {
	class MikroTik_User_Manager_Bridge {
		const DB_VERSION = '2.3.0';
		public static function init() : void {
			add_action( 'plugins_loaded', function(){
				load_plugin_textdomain( 'mikrotik-user-manager-bridge', false, dirname( plugin_basename( MUMB_FILE ) ) . '/languages/' );
			} );
			register_activation_hook( MUMB_FILE, [ __CLASS__, 'activate' ] );
			add_action( 'init', [ __CLASS__, 'register_cron' ] );
			add_action( 'mumb_sync_cron', [ __CLASS__, 'run_sync' ] );
			add_action( 'mumb_queue_cron', [ __CLASS__, 'run_queue' ] );
			add_action( 'init', [ __CLASS__, 'ensure_affiliate_role' ] );
			// Handle self-registrations - assign admin as default affiliate
			add_action( 'user_register', [ __CLASS__, 'handle_user_registration' ], 10, 1 );
			// React admin registers its own menu in MWP_Admin
			add_action( 'admin_post_mumb_sync_now', function(){
				if ( current_user_can('manage_options') && check_admin_referer('mumb_sync_now') ) {
					$res = ( new MUMB_Sync_Service() )->sync_all();
					if ( class_exists('MUMB_Logger') ) {
						MUMB_Logger::log( 'info', 'sync', 'Manual sync triggered', $res );
					}
					if ( isset($_SERVER['HTTP_REFERER']) ) {
						$url = add_query_arg( [ 'synced' => 1 ], wp_get_referer() );
						wp_safe_redirect( $url );
					} else {
						wp_safe_redirect( admin_url('admin.php?page=mikrotik_dashboard') );
					}
					exit;
				}
			} );
			// Ensure a minute schedule exists and auto-schedule events if missing
			add_filter( 'cron_schedules', function( $schedules ) {
				if ( ! isset( $schedules['minute'] ) ) {
					$schedules['minute'] = [ 'interval' => 60, 'display' => 'Once a minute' ];
				}
				return $schedules;
			} );
			add_action( 'admin_init', [ __CLASS__, 'ensure_cron' ] );
			add_action( 'admin_init', [ __CLASS__, 'maybe_upgrade_schema' ] );
			if ( file_exists( __DIR__ . '/admin/class-server-admin.php' ) ) {
				require_once __DIR__ . '/admin/class-server-admin.php';
			}
			if ( file_exists( __DIR__ . '/admin/class-health-dashboard.php' ) ) {
				require_once __DIR__ . '/admin/class-health-dashboard.php';
			}
			if ( file_exists( __DIR__ . '/admin/class-profile-admin.php' ) ) {
				require_once __DIR__ . '/admin/class-profile-admin.php';
			}
			if ( file_exists( __DIR__ . '/public/shortcode.php' ) ) {
				require_once __DIR__ . '/public/shortcode.php';
			}
			MUMB_REST::init();
			MWP_REST::init();
			// WooCommerce loads after this plugin alphabetically, so wait until all plugins are loaded.
			add_action( 'plugins_loaded', [ 'MUMB_Woo_Integration', 'init' ], 20 );
			MUMB_Mikrotik_Health::init();
			if ( class_exists( 'MUMB_Server_Admin' ) ) {
				MUMB_Server_Admin::init();
			}
			if ( class_exists( 'MUMB_Health_Dashboard_Admin' ) ) {
				MUMB_Health_Dashboard_Admin::init();
			}
			if ( class_exists( 'MUMB_Profile_Admin' ) ) {
				MUMB_Profile_Admin::init();
			}
			if ( class_exists( 'MUMB_Profile_Service' ) ) {
				MUMB_Profile_Service::init();
			}
		}

		public static function activate() : void {
			global $wpdb;
			$charset = $wpdb->get_charset_collate();
			$p = $wpdb->prefix;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . $p . "mikrotik_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            um_id VARCHAR(64) NULL,
            rest_id VARCHAR(64) NULL,
            username VARCHAR(190) NOT NULL,
            profile VARCHAR(190) NULL,
            comment TEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_username (username)
        ) $charset;" );
		dbDelta( 'CREATE TABLE ' . $p . "mikrotik_groups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            um_id VARCHAR(64) NULL,
            rest_id VARCHAR(64) NULL,
            name VARCHAR(190) NOT NULL,
            attributes TEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_name (name)
        ) $charset;" );
		dbDelta( 'CREATE TABLE ' . $p . "mikrotik_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            um_id VARCHAR(64) NULL,
            rest_id VARCHAR(64) NULL,
            name VARCHAR(190) NOT NULL,
            validity VARCHAR(64) NULL,
            raw JSON NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_name (name)
        ) $charset;" );
		dbDelta( 'CREATE TABLE ' . $p . "mikrotik_user_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            um_id VARCHAR(64) NULL,
            rest_id VARCHAR(64) NULL,
            username VARCHAR(190) NOT NULL,
            profile VARCHAR(190) NOT NULL,
            expires_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user (username),
            UNIQUE KEY uq_user_profile (username, profile)
        ) $charset;" );
		dbDelta( 'CREATE TABLE ' . $p . "mikrotik_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            time DATETIME NOT NULL,
            level VARCHAR(20) NOT NULL,
            action VARCHAR(64) NOT NULL,
            message TEXT NULL,
            meta LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY idx_time (time),
            KEY idx_action (action)
        ) $charset;" );
			MUMB_Server_Registry::create_table();
			// schedule sync if not set
			if ( ! wp_next_scheduled( 'mumb_sync_cron' ) ) {
				if ( ! isset( wp_get_schedules()['minute'] ) ) {
					add_filter( 'cron_schedules', function( $s ){ $s['minute'] = [ 'interval' => 60, 'display' => 'Once a minute' ]; return $s; } );
				}
				wp_schedule_event( time() + 60, isset( wp_get_schedules()['minute'] ) ? 'minute' : 'hourly', 'mumb_sync_cron' );
			}
			if ( ! wp_next_scheduled( 'mumb_queue_cron' ) ) {
				wp_schedule_event( time() + 90, isset( wp_get_schedules()['minute'] ) ? 'minute' : 'hourly', 'mumb_queue_cron' );
			}
			self::schedule_health_event();

			self::ensure_affiliate_role();
		}

		public static function maybe_upgrade_schema() : void {
			$ver = get_option( 'mumb_db_version', '' );
			if ( $ver === self::DB_VERSION ) { return; }
			global $wpdb; $p = $wpdb->prefix; $charset = $wpdb->get_charset_collate();
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . $p . "mikrotik_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            um_id VARCHAR(64) NULL,
            rest_id VARCHAR(64) NULL,
            username VARCHAR(190) NOT NULL,
            profile VARCHAR(190) NULL,
            comment TEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_username (username)
        ) $charset;" );
		dbDelta( 'CREATE TABLE ' . $p . "mikrotik_user_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            um_id VARCHAR(64) NULL,
            rest_id VARCHAR(64) NULL,
            username VARCHAR(190) NOT NULL,
            profile VARCHAR(190) NOT NULL,
            expires_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user (username),
            UNIQUE KEY uq_user_profile (username, profile)
        ) $charset;" );
		dbDelta( 'CREATE TABLE ' . $p . "mikrotik_groups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            um_id VARCHAR(64) NULL,
            rest_id VARCHAR(64) NULL,
            name VARCHAR(190) NOT NULL,
            attributes TEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_name (name)
        ) $charset;" );
		dbDelta( 'CREATE TABLE ' . $p . "mikrotik_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            um_id VARCHAR(64) NULL,
            rest_id VARCHAR(64) NULL,
            name VARCHAR(190) NOT NULL,
            validity VARCHAR(64) NULL,
            raw JSON NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_name (name)
        ) $charset;" );
			MUMB_Server_Registry::create_table();
			self::schedule_health_event();
			update_option( 'mumb_db_version', self::DB_VERSION, false );
		}

		public static function register_cron() : void {
			// no-op; activation handles schedule
		}

		public static function ensure_cron() : void {
			// Schedule sync hourly if missing
			if ( ! wp_next_scheduled( 'mumb_sync_cron' ) ) {
				wp_schedule_event( time() + 90, 'hourly', 'mumb_sync_cron' );
			}
			// Schedule queue every minute if available, else hourly
			if ( ! wp_next_scheduled( 'mumb_queue_cron' ) ) {
				$recurrence = isset( wp_get_schedules()['minute'] ) ? 'minute' : 'hourly';
				wp_schedule_event( time() + 120, $recurrence, 'mumb_queue_cron' );
			}
			self::schedule_health_event();
		}

		private static function schedule_health_event(): void {
			$recurrence = isset( wp_get_schedules()['minute'] ) ? 'minute' : 'hourly';
			if ( ! wp_next_scheduled( MUMB_Mikrotik_Health::CRON_HOOK ) ) {
				wp_schedule_event( time() + 60, $recurrence, MUMB_Mikrotik_Health::CRON_HOOK );
			}
		}

		public static function ensure_affiliate_role() : void {
			$role = get_role( 'affiliate_partner' );
			if ( ! $role ) {
				add_role( 'affiliate_partner', __( 'Affiliate Partner', 'mikrotik-user-manager-bridge'), [ 'read' => true ] );
				return;
			}
			if ( ! $role->has_cap( 'read' ) ) {
				$role->add_cap( 'read' );
			}
		}

		/**
		 * Handle user registration - assign admin as affiliate for self-registrations
		 */
		public static function handle_user_registration( int $user_id ) : void {
			// Assign a default affiliate owner (user ID 1 by default) to self-registered users.
			// Return 0 from the `mumb_default_affiliate_owner` filter to disable this behaviour.
			if ( ! get_user_meta( $user_id, 'mumb_affiliate_owner', true ) ) {
				$owner_id = (int) apply_filters( 'mumb_default_affiliate_owner', 1, $user_id );
				if ( $owner_id > 0 ) {
					update_user_meta( $user_id, 'mumb_affiliate_owner', $owner_id );
					update_user_meta( $user_id, 'created_by_affiliate', $owner_id );
				}
			}
			if ( class_exists( 'MUMB_Profile_Service' ) ) {
				MUMB_Profile_Service::ensure_user_binding( $user_id );
			} else {
				$user = get_userdata( $user_id );
				if ( $user instanceof \WP_User ) {
					update_user_meta( $user_id, 'mikrotik_username', sanitize_user( $user->user_login, true ) );
				}
			}
		}

		public static function run_sync() : void {
			( new MUMB_Sync_Service() )->sync_all();
		}

		public static function run_queue() : void {
			// Keep connection alive/update state continuously unless manually disconnected
			self::keepalive();
			MUMB_Queue::process();
		}

		protected static function keepalive() : void {
		$state = \MUMB\Services\SettingsService::get_connection_state();
			if ( ! empty( $state['manually_disconnected'] ) ) { return; }

			$settings = MUMB_Settings::load();
			$host = $settings->host;
			$username = $settings->username;
			$password_plain = $settings->password;
			if ( $host === '' || $username === '' || $password_plain === '' ) { return; }
			$prev = ! empty( $state['connected'] );
			// Jitter: occasionally skip to avoid synchronized flapping
			$jitter_ms = $settings->keepalive_jitter_ms;
			if ( $jitter_ms > 0 && ( rand(0, 1000) < min(1000, $jitter_ms) ) ) { return; }
			$failure_threshold = max( 1, $settings->keepalive_failure_threshold );
		$ka = \MUMB\Services\SettingsService::get_keepalive_state();
			try {
				$api = new MUMB_RouterOS_API(
                    $host,
                    $settings->port,
                    $username,
                    $password_plain,
                    $settings->ssl,
                    $settings->api_timeout,
                    $settings->verify_ssl
				);
				$res = $api->test();
				if ( ! empty( $res['success'] ) ) {
					$state['connected'] = 1; $state['last_success'] = current_time('mysql'); $state['last_error'] = '';
					\MUMB\Services\SettingsService::set_connection_state( $state );
					if ( ! $prev ) { $ka['failures'] = 0; \MUMB\Services\SettingsService::set_keepalive_state( $ka ); }
					if ( ! $prev ) {
						// Just recovered; trigger a background sync
						try { ( new MUMB_Sync_Service() )->sync_all(); } catch ( \Throwable $e ) { /* ignore */ }
					}
					return;
				}
				// fallthrough to mark disconnected
				$failures = isset($ka['failures']) && is_numeric($ka['failures']) ? (int)$ka['failures'] : 0;
				$ka['failures'] = $failures + 1; 
				\MUMB\Services\SettingsService::set_keepalive_state( $ka );
				if ( $ka['failures'] >= $failure_threshold ) {
					$state['connected'] = 0; $state['last_error'] = isset($res['message']) ? (string) $res['message'] : 'Unknown error';
					\MUMB\Services\SettingsService::set_connection_state( $state );
				}
			} catch ( \Throwable $e ) {
				$failures = isset($ka['failures']) && is_numeric($ka['failures']) ? (int)$ka['failures'] : 0;
				$ka['failures'] = $failures + 1; \MUMB\Services\SettingsService::set_keepalive_state( $ka );
				if ( $ka['failures'] >= $failure_threshold ) {
					$state['connected'] = 0; $state['last_error'] = $e->getMessage();
					\MUMB\Services\SettingsService::set_connection_state( $state );
				}
			}
		}
	}
}

if ( class_exists( 'MikroTik_User_Manager_Bridge' ) ) {
    MikroTik_User_Manager_Bridge::init();
}
