<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MUMB\Services\SettingsService;

class MUMB_Profile_Service {

	public const MODE_TEMPLATE = 'template';
	public const MODE_ROUTER   = 'router';

	private const TOKEN_PREFIX = 'mumb_profile_token_';

	public static function init(): void {
		add_action( 'user_register', array( __CLASS__, 'handle_user_register' ), 10, 1 );
		add_action( 'profile_update', array( __CLASS__, 'handle_profile_update' ), 10, 2 );
		add_action( 'wp_login', array( __CLASS__, 'handle_login' ), 10, 2 );
	}

	public static function handle_user_register( int $user_id ): void {
		self::ensure_user_binding( $user_id );
	}

	public static function handle_profile_update( int $user_id, WP_User $user ): void {
		self::ensure_user_binding( $user_id, $user->user_login ?? null );
	}

	public static function handle_login( string $user_login, WP_User $user ): void {
		self::ensure_user_binding( $user->ID, $user_login );
	}

	public static function ensure_user_binding( int $user_id, ?string $fallback_username = null ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$current = get_user_meta( $user_id, 'mikrotik_username', true );
		if ( is_string( $current ) && '' !== $current ) {
			return;
		}
		if ( null === $fallback_username ) {
			$user = get_userdata( $user_id );
			$fallback_username = $user instanceof WP_User ? $user->user_login : '';
		}
		if ( '' !== $fallback_username ) {
			update_user_meta( $user_id, 'mikrotik_username', sanitize_user( $fallback_username, true ) );
		}
	}

	public static function resolve_mikrotik_username( int $user_id ): string {
		self::ensure_user_binding( $user_id );
		$username = get_user_meta( $user_id, 'mikrotik_username', true );
		if ( ! is_string( $username ) || '' === $username ) {
			$user     = get_userdata( $user_id );
			$username = $user instanceof WP_User ? $user->user_login : '';
			if ( '' !== $username ) {
				$username = sanitize_user( $username, true );
				update_user_meta( $user_id, 'mikrotik_username', $username );
			}
		}
		return sanitize_user( (string) $username, true );
	}

	public static function issue_download_token( int $user_id, array $context = array() ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return new WP_Error( 'mumb_profile_token_user', __( 'Invalid user context.', 'mikrotik-user-manager-bridge' ) );
		}
		$ttl    = SettingsService::get_profile_token_ttl();
		$token  = wp_generate_password( 48, false, false );
		$key    = self::TOKEN_PREFIX . $token;
		$value  = array_merge(
			array(
				'user_id'   => $user_id,
				'issued_at' => time(),
			),
			$context
		);
		if ( ! set_transient( $key, $value, $ttl ) ) {
			return new WP_Error( 'mumb_profile_token_store', __( 'Failed to prepare download token.', 'mikrotik-user-manager-bridge' ) );
		}
		return $token;
	}

	public static function validate_download_token( string $token, int $user_id ) {
		$token = trim( $token );
		if ( '' === $token ) {
			return new WP_Error( 'mumb_profile_token_missing', __( 'Missing download token.', 'mikrotik-user-manager-bridge' ) );
		}
		$key   = self::TOKEN_PREFIX . $token;
		$data  = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mumb_profile_token_invalid', __( 'Download token expired or invalid.', 'mikrotik-user-manager-bridge' ) );
		}
		delete_transient( $key );
		if ( (int) ( $data['user_id'] ?? 0 ) !== (int) $user_id ) {
			return new WP_Error( 'mumb_profile_token_user_mismatch', __( 'Download token does not belong to this user.', 'mikrotik-user-manager-bridge' ) );
		}
		return $data;
	}

	public static function server_supports_downloads( array $server ): bool {
		$mode       = SettingsService::get_profile_mode();
		$has_tpl    = '' !== SettingsService::get_profile_template();
		$default_id = SettingsService::get_profile_default_server();
		if ( self::MODE_ROUTER === $mode ) {
			return true;
		}
		if ( self::MODE_TEMPLATE === $mode && $has_tpl && $default_id > 0 ) {
			return (int) ( $server['id'] ?? 0 ) === $default_id;
		}
		return false;
	}

	public static function get_default_server_id(): int {
		return SettingsService::get_profile_default_server();
	}

	public static function get_ovpn_profile( string $mikrotik_username, int $server_id, int $user_id = 0 ) {
		$mode  = SettingsService::get_profile_mode();
		$error = null;
		if ( self::MODE_ROUTER === $mode ) {
			$profile = self::fetch_router_profile( $mikrotik_username, $server_id );
			if ( ! is_wp_error( $profile ) && '' !== $profile ) {
				return $profile;
			}
			$error = $profile instanceof WP_Error ? $profile : null;
		}
		$template = SettingsService::get_profile_template();
		if ( '' === $template ) {
			if ( $error instanceof WP_Error ) {
				return $error;
			}
			return new WP_Error( 'mumb_profile_template_missing', __( 'Profile template is not configured.', 'mikrotik-user-manager-bridge' ) );
		}
		return self::build_template_profile( $template, $mikrotik_username, $server_id, $user_id );
	}

	private static function build_template_profile( string $template, string $mikrotik_username, int $server_id, int $user_id = 0 ): string {
		$server = MUMB_Server_Registry::get_server( $server_id );
		$host   = $server['hostname'] ?? '';
		if ( '' === $host && isset( $server['name'] ) ) {
			$host = (string) $server['name'];
		}
		$password = self::get_user_vpn_password( $user_id );
		$replacements = array(
			'<SERVER_IP>' => self::sanitize_placeholder_value( $host ),
			'<USERNAME>'  => self::sanitize_placeholder_value( $mikrotik_username ),
			'<PASSWORD>'  => self::sanitize_placeholder_value( $password ),
		);
		$content = strtr( $template, $replacements );
		/**
		 * Allow plugins to filter the generated OVPN profile.
		 *
		 * @param string $content
		 * @param array  $context
		 */
		return (string) apply_filters( 'mumb_profile_template_rendered', $content, array(
			'user_id'  => $user_id,
			'username' => $mikrotik_username,
			'server'   => $server,
		) );
	}

	private static function fetch_router_profile( string $mikrotik_username, int $server_id ) {
		$server = MUMB_Server_Registry::get_server( $server_id );
		if ( ! $server ) {
			return new WP_Error( 'mumb_profile_server_missing', __( 'Server not found for profile download.', 'mikrotik-user-manager-bridge' ) );
		}
		$settings = SettingsService::get_settings();
		if ( ! $settings->um_rest || '' === $settings->username || '' === $settings->password ) {
			return new WP_Error( 'mumb_profile_rest_disabled', __( 'Router REST API is not enabled.', 'mikrotik-user-manager-bridge' ) );
		}
		$scheme = 443 === (int) ( $server['rest_port'] ?? 443 ) ? 'https' : 'http';
		$port   = (int) ( $server['rest_port'] ?? 443 );
		$base   = sprintf( '%s://%s:%d', $scheme, $server['hostname'], $port > 0 ? $port : 443 );
		$rest   = new MUMB_UM_REST( $base, $settings->username, $settings->password, $settings->verify_ssl, $settings->api_timeout );
		$result = $rest->download_profile_config( $mikrotik_username );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['content'] ) && is_string( $result['content'] ) ) {
			return $result['content'];
		}
		if ( isset( $result['data'] ) && is_string( $result['data'] ) ) {
			return $result['data'];
		}
		return new WP_Error( 'mumb_profile_router_empty', __( 'Router did not return a profile configuration.', 'mikrotik-user-manager-bridge' ) );
	}

	private static function get_user_vpn_password( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$value = get_user_meta( $user_id, 'mikrotik_password', true );
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}
		/**
		 * Filter to provide a user-specific VPN password for template rendering.
		 */
		return (string) apply_filters( 'mumb_profile_user_password', '', $user_id );
	}

	public static function sanitize_template( string $template ): string {
		$template = str_replace( "\0", '', $template );
		$template = (string) preg_replace( "/\r\n?/", "\n", $template );
		return trim( $template );
	}

	private static function sanitize_placeholder_value( string $value ): string {
		$value = str_replace( array( "\r", "\n", "\0" ), '', $value );
		return trim( $value );
	}
}
