<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MUMB\Services\CapabilityService;
use MUMB\Services\SettingsService;

class MUMB_REST {

	private const DEFAULT_TTL = 900; // 15 minutes

	/** @var array{filename:string,content:string}|null */
	private static ?array $profile_download_payload = null;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			'mikrotik/v1',
			'/connect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'connect' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mikrotik/v1',
			'/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'disconnect' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mikrotik/v1',
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'ping' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mikrotik/v1',
			'/servers/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'servers_status' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'role'   => array(
						'sanitize_callback' => 'sanitize_key',
					),
					'status' => array(
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		register_rest_route(
			'mikrotik/v1',
			'/profile/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'profile_download' ),
				'permission_callback' => array( __CLASS__, 'profile_download_permission' ),
				'args'                => array(
					'token'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'server_id' => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
			)
		);
		register_rest_route(
			'mikrotik/v1',
			'/servers/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'servers_history' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'server_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'limit'     => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			'mikrotik/v1',
			'/servers/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'servers_refresh' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mikrotik/v1',
			'/servers/(?P<id>\d+)/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'server_refresh' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, message: string, info?: array<string, mixed>}|WP_REST_Response
	 */
	public static function connect( WP_REST_Request $req ): array|WP_REST_Response {
		/** @var mixed $host_param */
		$host_param = $req->get_param( 'host' );
		/** @var mixed $port_param */
		$port_param = $req->get_param( 'port' );
		/** @var mixed $username_param */
		$username_param = $req->get_param( 'username' );
		/** @var mixed $password_param */
		$password_param = $req->get_param( 'password' );
		/** @var mixed $ssl_param */
		$ssl_param = $req->get_param( 'ssl' );
		/** @var mixed $um_rest_param */
		$um_rest_param = $req->get_param( 'um_rest' );
		/** @var mixed $rest_base_param */
		$rest_base_param = $req->get_param( 'um_rest_base' );
		/** @var mixed $verify_ssl_param */
		$verify_ssl_param = $req->get_param( 'verify_ssl' );

		$host = sanitize_text_field( is_scalar( $host_param ) ? (string) $host_param : '' );
		$port = is_numeric( $port_param ) ? (int) $port_param : 0;
		if ( $port <= 0 ) {
			$port = 8728;
		}
		$username   = sanitize_text_field( is_scalar( $username_param ) ? (string) $username_param : '' );
		$password   = is_scalar( $password_param ) ? (string) $password_param : '';
		$ssl        = (bool) $ssl_param;
		$verify_ssl = isset( $verify_ssl_param ) ? (bool) $verify_ssl_param : true;
		$rest_base  = sanitize_text_field( is_scalar( $rest_base_param ) ? (string) $rest_base_param : '' );
		$want_rest      = (bool) $um_rest_param;
		$rest_requested = $want_rest || '' !== $rest_base;

		if ( '' === $host || '' === $username || '' === $password ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Missing host/username/password',
				),
				400
			);
		}
		// Try API first, then optionally fallback to REST if requested/base provided
		$api_ok   = false;
		$api_info = array();
		$api_err  = '';
		try {
			$api = new MUMB_RouterOS_API( $host, $port, $username, $password, $ssl, 8, $verify_ssl );
			$res = $api->test();
			if ( ! empty( $res['success'] ) ) {
				$api_ok        = true;
				$identity_rows = $api->print_resource( '/system/identity/print' );
				$resource_rows = $api->print_resource( '/system/resource/print' );
				$identity_info = $identity_rows[0] ?? array();
				if ( ! is_array( $identity_info ) ) {
					$identity_info = array();
				}
				$resource_info = $resource_rows[0] ?? array();
				if ( ! is_array( $resource_info ) ) {
					$resource_info = array();
				}
				$api_info = array(
					'identity' => $identity_info,
					'resource' => $resource_info,
				);
			} else {
				$api_err = 'unknown error';
				if ( isset( $res['message'] ) ) {
					$raw = $res['message'];
					if ( is_scalar( $raw ) ) {
						$api_err = $raw;
					}
				}
			}
		} catch ( \Throwable $e ) {
			$api_err = $e->getMessage();
		}

		$rest_ok  = false;
		$rest_err = '';
		if ( $rest_requested && ! $api_ok ) {
			try {
				$client = new MUMB_UM_REST( $rest_base, $username, $password, $verify_ssl, 8 );
				$check  = $client->list_profiles();
				if ( is_wp_error( $check ) ) {
					$rest_err = $check->get_error_message();
				} else {
					$rest_ok = true;
				}
			} catch ( \Throwable $e ) {
				$rest_err = $e->getMessage();
			}
		}

		if ( ! $api_ok && ! $rest_ok ) {
			$msg = 'Connect failed';
			if ( $api_err ) {
				$msg .= ' — API: ' . $api_err;
			}
			if ( $rest_err ) {
				$msg .= ' — REST: ' . $rest_err;
			}
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $msg,
				),
				400
			);
		}

		// Save settings (encrypt password)
		$settings             = SettingsService::get_settings();
		$settings->host       = $host;
		$settings->port       = $port;
		$settings->username   = $username;
		$settings->password   = $password;
		$settings->ssl        = $ssl;
		$settings->verify_ssl = $verify_ssl;
		// Always use User Manager
		$settings->um_rest = true;
		if ( $rest_requested || $rest_ok ) {
			if ( $rest_base ) {
				$settings->um_rest_base = esc_url_raw( $rest_base );
			}
		}
		SettingsService::save_settings( $settings );

		// Connection state
		$state = SettingsService::update_connection_state(
			array(
				'connected'             => 1,
				'manually_disconnected' => 0,
				'last_success'          => current_time( 'mysql' ),
				'last_error'            => '',
			)
		);
		if ( isset( $api_info['resource'] ) ) {
			/** @var mixed $resource_data */
			$resource_data = $api_info['resource'];
			if ( is_array( $resource_data ) ) {
				if ( isset( $resource_data['version'] ) ) {
					/** @var mixed $ver */
					$ver = $resource_data['version'];
					if ( is_scalar( $ver ) ) {
						$state['api_version'] = $ver;
					}
				}
			}
		}
		if ( ! empty( $api_info ) ) {
			set_transient( 'mumb_server_info', $api_info, self::transient_ttl() );
		}
		SettingsService::set_connection_state( $state );

		// Probe capabilities after saving settings so future requests can rely on cached results.
		try {
			( new CapabilityService() )->probe();
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'warning', 'capabilities', 'Capability probe on connect failed', array( 'error' => $e->getMessage() ) );
			}
		}
		// Trigger a full sync on connect so all tables are fresh
		try {
			( new MUMB_Sync_Service() )->sync_all();
		} catch ( \Throwable $e ) {
			/* ignore */
		}
		return array(
			'success' => true,
			'message' => $api_ok ? 'Connected (API)' : 'Connected (REST)',
			'info'    => $api_info,
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public static function servers_status( WP_REST_Request $request = null ): WP_REST_Response {
		$args = array();
		if ( $request instanceof WP_REST_Request ) {
			$role   = $request->get_param( 'role' );
			$status = $request->get_param( 'status' );
			if ( is_string( $role ) && '' !== $role ) {
				$role = sanitize_key( $role );
				if ( in_array( $role, array( MUMB_Server_Registry::ROLE_USERMANAGER, MUMB_Server_Registry::ROLE_VPN ), true ) ) {
					$args['role'] = $role;
				}
			}
			if ( is_string( $status ) && '' !== $status ) {
				$status = sanitize_key( $status );
				if ( in_array( $status, array( MUMB_Server_Registry::STATUS_ONLINE, MUMB_Server_Registry::STATUS_DEGRADED, MUMB_Server_Registry::STATUS_OFFLINE ), true ) ) {
					$args['status'] = $status;
				}
			}
		}
		$servers = array();
		foreach ( MUMB_Server_Registry::get_servers( $args ) as $server ) {
			$servers[] = array(
				'id'           => (int) $server['id'],
				'name'         => $server['name'],
				'hostname'     => $server['hostname'],
				'role'         => $server['role'],
				'status'       => $server['status'],
				'latency_ms'   => $server['latency_ms'],
				'active_users' => $server['active_users'],
				'last_ping'    => $server['last_ping'],
				'uptime_seconds' => $server['uptime_seconds'],
				'cpu_load'       => $server['cpu_load'],
				'memory_usage'   => $server['memory_usage'],
				'bandwidth_bps'  => $server['bandwidth_bps'],
			);
		}
		return new WP_REST_Response(
			array(
				'generated_at' => current_time( 'mysql', true ),
				'count'        => count( $servers ),
				'servers'      => $servers,
			)
		);
	}

	public static function servers_history( WP_REST_Request $request ): WP_REST_Response {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Unauthorized.', 'mikrotik-user-manager-bridge' ) ), 403 );
		}
		$server_id = (int) $request->get_param( 'server_id' );
		$limit     = (int) $request->get_param( 'limit' );
		if ( $server_id <= 0 ) {
			return new WP_REST_Response( array( 'message' => __( 'Server ID is required.', 'mikrotik-user-manager-bridge' ) ), 400 );
		}
		if ( $limit <= 0 ) {
			$limit = 20;
		}
		$limit = min( 100, $limit );
		$rows  = MUMB_Server_Registry::get_server_history( $server_id, $limit );
		return new WP_REST_Response( array( 'server_id' => $server_id, 'entries' => $rows ) );
	}

	public static function servers_refresh( WP_REST_Request $request ): WP_REST_Response {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Unauthorized.', 'mikrotik-user-manager-bridge' ) ), 403 );
		}
		$ids = $request->get_param( 'ids' );
		if ( is_array( $ids ) && ! empty( $ids ) ) {
			$refreshed = array();
			foreach ( $ids as $id ) {
				$results = MUMB_Mikrotik_Health::refresh_server( (int) $id );
				if ( $results ) {
					$refreshed[] = (int) $id;
				}
			}
			return new WP_REST_Response( array( 'refreshed' => $refreshed ) );
		}
		$servers = MUMB_Server_Registry::get_servers();
		foreach ( $servers as $server ) {
			MUMB_Mikrotik_Health::refresh_server( (int) $server['id'] );
		}
		return new WP_REST_Response( array( 'refreshed' => wp_list_pluck( $servers, 'id' ) ) );
	}

	public static function server_refresh( WP_REST_Request $request ): WP_REST_Response {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Unauthorized.', 'mikrotik-user-manager-bridge' ) ), 403 );
		}
		$server_id = (int) $request['id'];
		if ( $server_id <= 0 ) {
			return new WP_REST_Response( array( 'message' => __( 'Server ID is required.', 'mikrotik-user-manager-bridge' ) ), 400 );
		}
		$result = MUMB_Mikrotik_Health::refresh_server( $server_id );
		if ( ! $result ) {
			return new WP_REST_Response( array( 'message' => __( 'Server not found.', 'mikrotik-user-manager-bridge' ) ), 404 );
		}
		return new WP_REST_Response( array( 'refreshed' => $server_id, 'status' => $result ) );
	}

	public static function profile_download_permission(): bool {
		return is_user_logged_in();
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function profile_download( WP_REST_Request $request ): WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			return new WP_REST_Response( array( 'message' => __( 'Authentication required.', 'mikrotik-user-manager-bridge' ) ), 401 );
		}
		if ( ! class_exists( 'MUMB_Profile_Service' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Profile service is unavailable.', 'mikrotik-user-manager-bridge' ) ), 500 );
		}
		$user_id  = get_current_user_id();
		$token    = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$context  = MUMB_Profile_Service::validate_download_token( $token, $user_id );
		if ( is_wp_error( $context ) ) {
			return new WP_REST_Response( array( 'message' => $context->get_error_message() ), 403 );
		}
		$server_id = $request->get_param( 'server_id' );
		$server_id = is_numeric( $server_id ) ? (int) $server_id : 0;
		if ( $server_id <= 0 && isset( $context['server_id'] ) ) {
			$server_id = (int) $context['server_id'];
		}
		if ( $server_id <= 0 ) {
			$server_id = MUMB_Profile_Service::get_default_server_id();
		}
		if ( $server_id <= 0 ) {
			return new WP_REST_Response( array( 'message' => __( 'No server configured for profile downloads.', 'mikrotik-user-manager-bridge' ) ), 400 );
		}
		$username = MUMB_Profile_Service::resolve_mikrotik_username( $user_id );
		if ( '' === $username ) {
			return new WP_REST_Response( array( 'message' => __( 'User does not have a MikroTik username.', 'mikrotik-user-manager-bridge' ) ), 400 );
		}
		$content = MUMB_Profile_Service::get_ovpn_profile( $username, $server_id, $user_id );
		if ( is_wp_error( $content ) ) {
			return new WP_REST_Response( array( 'message' => $content->get_error_message() ), 500 );
		}
		$filename = sanitize_file_name( $username . '.ovpn' );
		if ( '' === $filename ) {
			$filename = 'profile.ovpn';
		}
		self::$profile_download_payload = array(
			'filename' => $filename,
			'content'  => (string) $content,
		);
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_profile_download' ), 10, 4 );
		return new WP_REST_Response( null, 200 );
	}

	public static function serve_profile_download( bool $served, WP_REST_Response $result, WP_REST_Request $request, \WP_REST_Server $server ): bool {
		if ( null === self::$profile_download_payload ) {
			return $served;
		}
		$payload = self::$profile_download_payload;
		self::$profile_download_payload = null;
		remove_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_profile_download' ), 10 );
		nocache_headers();
		header( 'Content-Type: application/x-openvpn-profile' );
		header( 'Content-Disposition: attachment; filename="' . $payload['filename'] . '"' );
		header( 'Content-Length: ' . strlen( $payload['content'] ) );
		echo $payload['content'];
		return true;
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}
	 */
	public static function disconnect( WP_REST_Request $req ): array {
		SettingsService::update_connection_state(
			array(
				'connected'             => 0,
				'last_error'            => '',
				'manually_disconnected' => 1,
			)
		);
		delete_transient( 'mumb_server_info' );
		return array( 'success' => true );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, latency_ms?: int}|WP_REST_Response
	 */
	public static function ping( WP_REST_Request $req ): array|WP_REST_Response {
		$settings = MUMB_Settings::load();
		if ( ! $settings->is_configured() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Not configured',
				),
				400
			);
		}
		$pass = $settings->decrypted_password();
		try {
			$api = new MUMB_RouterOS_API(
				$settings->host,
				$settings->port,
				$settings->username,
				$pass,
				$settings->ssl,
				$settings->api_timeout,
				$settings->verify_ssl
			);
			$api->login();
			$t0 = microtime( true );
			$api->request( array( '/ping', '=count=1' ) );
			$lat = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			/** @var mixed $info_raw */
			$info_raw           = get_transient( 'mumb_server_info' );
			$info               = is_array( $info_raw ) ? $info_raw : array();
			$info['latency_ms'] = $lat;
			set_transient( 'mumb_server_info', $info, self::transient_ttl() );
			return array(
				'success'    => true,
				'latency_ms' => $lat,
			);
		} catch ( \Throwable $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	private static function transient_ttl(): int {
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
		return $minute > 0 ? 15 * $minute : self::DEFAULT_TTL;
	}
}
