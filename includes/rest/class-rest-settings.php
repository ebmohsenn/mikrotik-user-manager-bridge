<?php

namespace MUMB\REST;

use MUMB_Bootstrap_Sync;
use MUMB_Branding;
use MUMB_Logger;
use MUMB_Queue;
use MUMB_Settings;
use MUMB_Sync_Service;
use MUMB\Services\CapabilityService;
use MUMB\Services\SettingsService;
use WP_REST_Request;
use WP_REST_Response;

class Settings_Controller {

	public static function register_routes(): void {
		register_rest_route(
			'mwp/v1',
			'/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_settings' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_settings' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/bootstrap-sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'bootstrap_sync' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/router/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'router_health' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/queue',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'queue_list' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/queue/(?P<key>[^/]+)/retry',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'queue_retry' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function get_settings( WP_REST_Request $req ): array|WP_REST_Response {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Unauthorized' ),
				),
				403
			);
		}

		$settings = MUMB_Settings::load();
		$masked   = $settings->to_array();
		if ( isset( $masked['password'] ) && $masked['password'] !== '' ) {
			$masked['password'] = '********';
		}

		$state = \MWP_REST::connection_state();

		$cli = SettingsService::get_cli_token();

		return array(
			'success' => true,
			'data'    => array(
				'settings'                => $masked,
				'state'                   => $state,
				'branding'                => MUMB_Branding::to_array(),
				'affiliate_default_group' => SettingsService::get_affiliate_default_group(),
				'cli_token_created'       => $cli['created'],
				'cli_token_hint'          => $cli['hint'],
				'profile_product_map'     => SettingsService::get_profile_map(),
				'master_mode'             => SettingsService::is_master_mode() ? 1 : 0,
			),
		);
	}

	public static function update_settings( WP_REST_Request $req ): array|WP_REST_Response {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Unauthorized' ),
				),
				403
			);
		}

		$state       = \MWP_REST::connection_state();
		$unlock      = (bool) $req->get_param( 'unlock' );
		$body_params = $req->get_json_params();
		if ( ! is_array( $body_params ) ) {
			$body_params = array();
		}

		$connection_keys         = array(
			'host',
			'port',
			'username',
			'password',
			'ssl',
			'verify_ssl',
			'um_rest_base',
			'api_timeout',
			'retry_max',
			'retry_base_ms',
			'retry_jitter_ms',
			'keepalive_jitter_ms',
			'keepalive_failure_threshold',
			'mirror_authority',
		);
		$has_connection_mutation = false;
		foreach ( $connection_keys as $ckey ) {
			if ( array_key_exists( $ckey, $body_params ) ) {
				$has_connection_mutation = true;
				break;
			}
		}

		if ( ! empty( $state['connected'] ) && ! $unlock && $has_connection_mutation ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Settings are locked while connected. Disconnect first to edit.' ),
					'code'    => 'locked',
				),
				409
			);
		}

		$settings = MUMB_Settings::load();
		$host     = sanitize_text_field( (string) $req->get_param( 'host' ) );
		if ( '' !== $host ) {
			$settings->host = $host;
		}
		/** @var mixed $port_param */
		$port_param = $req->get_param( 'port' );
		/** @var int $port */
		$port = absint( is_scalar( $port_param ) ? $port_param : 0 );
		if ( $port > 0 ) {
			$settings->port = $port;
		}
		$username = sanitize_text_field( (string) $req->get_param( 'username' ) );
		if ( '' !== $username ) {
			$settings->username = $username;
		}
		$password_param = (string) $req->get_param( 'password' );
		if ( '' !== $password_param ) {
			$settings->password = $password_param;
		}
		$settings->ssl = (bool) $req->get_param( 'ssl' );
		if ( null !== $req->get_param( 'verify_ssl' ) ) {
			$settings->verify_ssl = (bool) $req->get_param( 'verify_ssl' );
		}
		// Always use User Manager - no toggle needed
		$settings->um_rest = true;
		$rest_base         = (string) $req->get_param( 'um_rest_base' );
		if ( '' !== $rest_base ) {
			$settings->um_rest_base = esc_url_raw( $rest_base );
		}
		// No custom CA path - use system defaults
		/** @var mixed $timeout_param */
		$timeout_param = $req->get_param( 'api_timeout' );
		/** @var int $timeout */
		$timeout = absint( is_scalar( $timeout_param ) ? $timeout_param : 0 );
		if ( $timeout > 0 ) {
			$settings->api_timeout = $timeout;
		}
		/** @var mixed $retry_max_param */
		$retry_max_param = $req->get_param( 'retry_max' );
		/** @var int $retry_max */
		$retry_max = absint( is_scalar( $retry_max_param ) ? $retry_max_param : 0 );
		if ( $retry_max > 0 ) {
			$settings->retry_max = $retry_max;
		}
		/** @var mixed $retry_base_param */
		$retry_base_param = $req->get_param( 'retry_base_ms' );
		/** @var int $retry_base */
		$retry_base = absint( is_scalar( $retry_base_param ) ? $retry_base_param : 0 );
		if ( $retry_base > 0 ) {
			$settings->retry_base_ms = $retry_base;
		}
		/** @var mixed $retry_jitter_param */
		$retry_jitter_param = $req->get_param( 'retry_jitter_ms' );
		/** @var int $retry_jitter */
		$retry_jitter = absint( is_scalar( $retry_jitter_param ) ? $retry_jitter_param : 0 );
		if ( $retry_jitter > 0 ) {
			$settings->retry_jitter_ms = $retry_jitter;
		}
		/** @var mixed $keepalive_jitter_param */
		$keepalive_jitter_param = $req->get_param( 'keepalive_jitter_ms' );
		/** @var int $keepalive_jitter */
		$keepalive_jitter = absint( is_scalar( $keepalive_jitter_param ) ? $keepalive_jitter_param : 0 );
		if ( $keepalive_jitter > 0 ) {
			$settings->keepalive_jitter_ms = $keepalive_jitter;
		}
		/** @var mixed $keepalive_thresh_param */
		$keepalive_thresh_param = $req->get_param( 'keepalive_failure_threshold' );
		/** @var int $keepalive_thresh */
		$keepalive_thresh = absint( is_scalar( $keepalive_thresh_param ) ? $keepalive_thresh_param : 0 );
		if ( $keepalive_thresh > 0 ) {
			$settings->keepalive_failure_threshold = $keepalive_thresh;
		}
		$mirror = (string) $req->get_param( 'mirror_authority' );
		if ( in_array( $mirror, array( 'router', 'wp' ), true ) ) {
			$settings->mirror_authority = $mirror;
		}

		SettingsService::save_settings( $settings );

			/** @var mixed $brand_name_param */
			$brand_name_param = $req->get_param( 'brand_name' );
			/** @var mixed $brand_logo_param */
			$brand_logo_param = $req->get_param( 'brand_logo' );
		if ( null !== $brand_name_param || null !== $brand_logo_param ) {
			$brand_name_value = null;
			$brand_logo_value = null;
			if ( null !== $brand_name_param && is_scalar( $brand_name_param ) ) {
				$brand_name_value = (string) $brand_name_param;
			}
			if ( null !== $brand_logo_param && is_scalar( $brand_logo_param ) ) {
				$brand_logo_value = (string) $brand_logo_param;
			}
			MUMB_Branding::update( $brand_name_value, $brand_logo_value );
		}

			/** @var mixed $affiliate_default_group_param */
			$affiliate_default_group_param = $req->get_param( 'affiliate_default_group' );
		if ( null !== $affiliate_default_group_param ) {
			$candidate = is_scalar( $affiliate_default_group_param ) ? sanitize_text_field( (string) $affiliate_default_group_param ) : '';
			if ( '' === $candidate ) {
				SettingsService::set_affiliate_default_group( null );
				if ( class_exists( 'MUMB_Logger' ) ) {
					MUMB_Logger::log(
						'notice',
						'affiliate_default_group_cleared',
						'Affiliate default group cleared',
						array(
							'user_id' => get_current_user_id(),
						)
					);
				}
			} else {
				global $wpdb;
				/** @var string $p */
				$p = $wpdb->prefix;
				/** @var string $query */
				$query = $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $p . 'mikrotik_groups WHERE name=%s', $candidate );
				$exists = (int) $wpdb->get_var( $query );
				if ( $exists <= 0 ) {
					return new WP_REST_Response(
						array(
							'success' => false,
							'message' => self::msg( 'Group not found' ),
						),
						400
					);
				}
				SettingsService::set_affiliate_default_group( $candidate );
				if ( class_exists( 'MUMB_Logger' ) ) {
					MUMB_Logger::log(
						'notice',
						'affiliate_default_group_updated',
						'Affiliate default group updated',
						array(
							'user_id' => get_current_user_id(),
							'group'   => $candidate,
						)
					);
				}
			}
		}

		/** @var mixed $profile_product_map_param */
		$profile_product_map_param = $req->get_param( 'profile_product_map' );
		if ( null !== $profile_product_map_param ) {
			$decoded = array();
			if ( is_string( $profile_product_map_param ) && '' !== $profile_product_map_param ) {
				/** @var mixed $candidate */
				$candidate = json_decode( $profile_product_map_param, true );
				if ( is_array( $candidate ) ) {
					$decoded = $candidate;
				}
			} elseif ( is_array( $profile_product_map_param ) ) {
				$decoded = $profile_product_map_param;
			}
			$clean = array();
			/**
			 * @var mixed $profile
			 * @var mixed $product_id
			 */
			foreach ( $decoded as $profile => $product_id ) {
				if ( ! is_string( $profile ) || '' === $profile ) {
					continue;
				}
				if ( is_numeric( $product_id ) ) {
					$clean[ $profile ] = (int) $product_id;
				}
			}
			SettingsService::set_profile_map( $clean );
		}

		/** @var mixed $master_mode_param */
		$master_mode_param = $req->get_param( 'master_mode' );
		if ( null !== $master_mode_param ) {
			SettingsService::set_master_mode( (int) ( is_scalar( $master_mode_param ) ? $master_mode_param : 0 ) === 1 );
		}

		if ( $has_connection_mutation ) {
			try {
				$api = new \MUMB_RouterOS_API( $settings->host, $settings->port, $settings->username, $settings->password, $settings->ssl );
				$res = $api->test();
				if ( ! empty( $res['success'] ) ) {
					$state = SettingsService::update_connection_state(
						array(
							'connected'    => 1,
							'last_success' => current_time( 'mysql' ),
							'last_error'   => '',
						)
					);
					SettingsService::set_connection_state( $state );
					try {
						( new CapabilityService() )->probe();
					} catch ( \Throwable $probe_error ) {
						if ( class_exists( 'MUMB_Logger' ) ) {
							MUMB_Logger::log( 'warning', 'capabilities', 'Capability probe during settings update failed', array( 'error' => $probe_error->getMessage() ) );
						}
					}
					( new MUMB_Sync_Service() )->sync_all();
				}
			} catch ( \Throwable $e ) {
				// ignore, handled elsewhere
			}
		}

		return array( 'success' => true );
	}

	public static function bootstrap_sync( WP_REST_Request $req ): array|WP_REST_Response {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Unauthorized' ),
				),
				403
			);
		}

		$mode_param = strtolower( (string) $req->get_param( 'mode' ) );
		$mode       = $mode_param === 'commit' ? 'commit' : 'dry-run';

		$bootstrap = new MUMB_Bootstrap_Sync();
		try {
			$result = $mode === 'commit' ? $bootstrap->commit() : $bootstrap->dry_run();
		} catch ( \Throwable $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}

		if ( $mode === 'dry-run' && isset( $result['mode'] ) && $result['mode'] !== 'dry-run' ) {
			$result['mode'] = 'dry-run';
		}

		if ( empty( $result['success'] ) ) {
			$message = isset( $result['message'] ) && is_scalar( $result['message'] ) ? (string) $result['message'] : __( 'Bootstrap sync failed.', 'mikrotik-user-manager-bridge' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $message,
					'data'    => $result,
				),
				500
			);
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}

	public static function queue_list( WP_REST_Request $req ): array|WP_REST_Response {
		return array(
			'success' => true,
			'data'    => MUMB_Queue::get_queue_snapshot(),
		);
	}

	public static function queue_retry( WP_REST_Request $req ): array|WP_REST_Response {
		$key = (string) $req->get_param( 'key' );
		if ( '' === $key ) {
			$key = (string) ( $req->get_url_params()['key'] ?? '' );
		}
		if ( '' === $key ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Missing job key' ),
				),
				400
			);
		}
		$from_history = (bool) $req->get_param( 'from_history' );
		$ok           = MUMB_Queue::retry_job( $key, $from_history );
		if ( ! $ok ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Job not found' ),
				),
				404
			);
		}
		return array( 'success' => true );
	}

	public static function router_health( WP_REST_Request $req ): array|WP_REST_Response {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Unauthorized' ),
				),
				403
			);
		}

		$settings = MUMB_Settings::load();
		$caps     = array();
		try {
			$caps = ( new CapabilityService() )->get_capabilities();
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'debug', 'capabilities', 'Router health capability fetch failed', array( 'error' => $e->getMessage() ) );
			}
		}

		$health = array(
			'configured'   => $settings->is_configured(),
			'master_mode'  => SettingsService::is_master_mode(),
			'binary'       => array(
				'reachable' => false,
				'message'   => '',
			),
			'rest'         => array(
				'configured'   => $settings->um_rest && $settings->um_rest_base,
				'reachable'    => null,
				'user_profile' => null,
				'message'      => '',
			),
			'capabilities' => $caps,
			'last_errors'  => array(),
		);

		if ( ! $health['configured'] ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $health,
				)
			);
		}

		try {
			$api    = new \MUMB_RouterOS_API( $settings->host, $settings->port, $settings->username, $settings->password, $settings->ssl, $settings->api_timeout );
			$result = $api->test();
			if ( ! empty( $result['success'] ) ) {
				$health['binary']['reachable'] = true;
			} else {
				$health['binary']['message'] = isset( $result['message'] ) ? $result['message'] : '';
			}
		} catch ( \Throwable $e ) {
			$health['binary']['message'] = $e->getMessage();
		}

		if ( $health['rest']['configured'] ) {
			try {
				$client = new \MUMB_UM_REST( (string) $settings->um_rest_base, $settings->username, $settings->decrypted_password(), $settings->verify_ssl, $settings->api_timeout );
				$users  = $client->list_users();
				if ( is_wp_error( $users ) ) {
					$health['rest']['reachable'] = false;
					$health['rest']['message']   = $users->get_error_message();
				} else {
					$health['rest']['reachable'] = true;
					$profiles                    = $client->list_user_profiles();
					if ( is_wp_error( $profiles ) ) {
						$health['rest']['user_profile'] = false;
					} else {
						$health['rest']['user_profile'] = true;
					}
				}
			} catch ( \Throwable $e ) {
				$health['rest']['reachable'] = false;
				$health['rest']['message']   = $e->getMessage();
			}
		}

		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $health,
				)
			); // $wpdb not available
		}
		/** @var string $p */
		$p = $wpdb->prefix;
		/** @var string|false $sql */
		$sql = $wpdb->prepare( 'SELECT time, level, action, message FROM `%s` WHERE level IN (%s,%s) ORDER BY time DESC LIMIT 5', $p . 'mikrotik_logs', 'error', 'warning' );
		/** @var mixed $logs */
		$logs = null;
		if ( is_string( $sql ) ) {
			$logs = $wpdb->get_results( $sql, ARRAY_A );
		}
		if ( is_array( $logs ) ) {
			$health['last_errors'] = array_map(
				static function ( $row ) {
					return array(
						'time'    => $row['time'] ?? '',
						'level'   => $row['level'] ?? '',
						'action'  => $row['action'] ?? '',
						'message' => $row['message'] ?? '',
					);
				},
				$logs
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $health,
			)
		);
	}

	private static function msg( string $text ): string {
		return \__( $text, 'mikrotik-user-manager-bridge' );
	}
}
