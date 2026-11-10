<?php

namespace MUMB\Services;

use MUMB_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralised accessor for plugin settings and state options.
 */
class SettingsService {

	private const OPTION_SETTINGS          = 'mumb_settings';
	private const OPTION_CONNECTION_STATE  = 'mumb_connection_state';
	private const OPTION_MASTER_MODE       = 'mumb_master_mode';
	private const OPTION_PROFILE_MAP       = 'mumb_profile_product_map';
	private const OPTION_AFFILIATE_DEFAULT = 'mumb_affiliate_default_group';
	private const OPTION_PROFILE_TEMPLATE   = 'mumb_profile_template';
	private const OPTION_PROFILE_MODE       = 'mumb_profile_mode';
	private const OPTION_PROFILE_DEFAULT    = 'mumb_profile_default_server';
	private const OPTION_PROFILE_TOKEN_TTL  = 'mumb_profile_token_ttl';
	private const OPTION_CLI_TOKEN_HASH    = 'mumb_cli_token_hash';
	private const OPTION_CLI_TOKEN_CREATED = 'mumb_cli_token_created';
	private const OPTION_CLI_TOKEN_HINT    = 'mumb_cli_token_hint';
	private const OPTION_CLI_TOKEN_ALG     = 'mumb_cli_token_alg';
	private const OPTION_KEEPALIVE_STATE   = 'mumb_keepalive_state';
	private const OPTION_JOB_QUEUE         = 'mumb_job_queue';
	private const OPTION_JOB_HISTORY       = 'mumb_job_history';

	private static bool $cli_token_migration_logged = false;

	/**
	 * Load the persisted settings object.
	 */
	public static function get_settings(): MUMB_Settings {
		return MUMB_Settings::load();
	}

	/**
	 * Persist the provided settings object.
	 */
	public static function save_settings( MUMB_Settings $settings ): void {
		update_option( self::OPTION_SETTINGS, $settings->to_array(), false );
	}

	/**
	 * Return raw settings array for lightweight consumers.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings_array(): array {
		/** @var mixed $raw */
		$raw = get_option( self::OPTION_SETTINGS, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_router_credentials(): array {
		$settings = self::get_settings();
		$host     = $settings->host;
		$username = $settings->username;
		$password = $settings->password;

		if ( '' === $host || '' === $username || '' === $password ) {
			return array();
		}

		$ssl  = $settings->ssl;
		$port = $settings->port;
		if ( $port <= 0 ) {
			$port = $ssl ? 8729 : 8728;
		}

		$payload               = array(
			'host'        => $host,
			'port'        => $port,
			'username'    => $username,
			'password'    => $password,
			'ssl'         => $ssl,
			'api_timeout' => $settings->api_timeout,
		);
		$payload['verify_ssl'] = $settings->verify_ssl;
		// No custom CA path - use system defaults

		foreach ( array( 'retry_max', 'retry_base_ms', 'retry_jitter_ms' ) as $key ) {
			/** @var mixed $value */
			$value           = $settings->{$key};
			$payload[ $key ] = $value;
		}

		$payload['um_rest'] = $settings->um_rest;
		if ( isset( $settings->um_rest_base ) && $settings->um_rest_base ) {
			$payload['um_rest_base'] = $settings->um_rest_base;
		}

		return $payload;
	}

	/**
	 * Retrieve cached connection/capability state.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_connection_state(): array {
		/** @var mixed $raw */
		$raw = get_option( self::OPTION_CONNECTION_STATE, array() );
		if ( is_string( $raw ) ) {
			/** @var mixed $decoded */
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Replace stored connection/capability state.
	 *
	 * @param array<string, mixed> $state
	 */
	public static function set_connection_state( array $state ): void {
		update_option( self::OPTION_CONNECTION_STATE, $state, false );
	}

	/**
	 * Merge values into stored connection/capability state.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	public static function update_connection_state( array $values ): array {
		$state = array_merge( self::get_connection_state(), $values );
		self::set_connection_state( $state );
		return $state;
	}

	/**
	 * Whether WordPress operates in master mode.
	 */
	public static function is_master_mode(): bool {
		$option_value = get_option( self::OPTION_MASTER_MODE, 0 );
		return is_numeric( $option_value ) ? (int) $option_value === 1 : false;
	}

	public static function set_master_mode( bool $enabled ): void {
		update_option( self::OPTION_MASTER_MODE, $enabled ? 1 : 0, false );
	}

	/**
	 * Fetch profile → product map.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_profile_map(): array {
		$raw = get_option( self::OPTION_PROFILE_MAP, array() );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Persist the profile → product map.
	 *
	 * @param array<string, mixed> $map
	 */
	public static function set_profile_map( array $map ): void {
		update_option( self::OPTION_PROFILE_MAP, $map, false );
	}

	public static function get_affiliate_default_group(): string {
		$raw = get_option( self::OPTION_AFFILIATE_DEFAULT, '' );
		return is_string( $raw ) ? $raw : '';
	}

	public static function set_affiliate_default_group( ?string $group ): void {
		if ( null === $group || '' === $group ) {
			delete_option( self::OPTION_AFFILIATE_DEFAULT );
			return;
		}
		update_option( self::OPTION_AFFILIATE_DEFAULT, $group, false );
	}

	public static function get_profile_template(): string {
		$raw = get_option( self::OPTION_PROFILE_TEMPLATE, '' );
		return is_string( $raw ) ? $raw : '';
	}

	public static function set_profile_template( string $template ): void {
		if ( '' === $template ) {
			delete_option( self::OPTION_PROFILE_TEMPLATE );
			return;
		}
		update_option( self::OPTION_PROFILE_TEMPLATE, $template, false );
	}

	public static function get_profile_mode(): string {
		$raw  = get_option( self::OPTION_PROFILE_MODE, 'template' );
		$mode = is_string( $raw ) ? strtolower( $raw ) : 'template';
		return in_array( $mode, array( 'template', 'router' ), true ) ? $mode : 'template';
	}

	public static function set_profile_mode( string $mode ): void {
		$mode = strtolower( $mode );
		if ( ! in_array( $mode, array( 'template', 'router' ), true ) ) {
			$mode = 'template';
		}
		update_option( self::OPTION_PROFILE_MODE, $mode, false );
	}

	public static function get_profile_default_server(): int {
		$value = get_option( self::OPTION_PROFILE_DEFAULT, 0 );
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	public static function set_profile_default_server( int $server_id ): void {
		if ( $server_id <= 0 ) {
			delete_option( self::OPTION_PROFILE_DEFAULT );
			return;
		}
		update_option( self::OPTION_PROFILE_DEFAULT, $server_id, false );
	}

	public static function get_profile_token_ttl(): int {
		$value = get_option( self::OPTION_PROFILE_TOKEN_TTL, 300 );
		if ( ! is_numeric( $value ) ) {
			return 300;
		}
		$ttl = (int) $value;
		if ( $ttl < 60 ) {
			$ttl = 60;
		}
		return $ttl;
	}

	public static function set_profile_token_ttl( int $ttl ): void {
		$ttl = max( 60, $ttl );
		update_option( self::OPTION_PROFILE_TOKEN_TTL, $ttl, false );
	}

	/**
	 * @return array{hash:string, alg:string, hint:string, created:string}
	 */
	public static function get_cli_token(): array {
		$hash           = self::read_secret_option( self::OPTION_CLI_TOKEN_HASH );
		$alg_option     = get_option( self::OPTION_CLI_TOKEN_ALG, '' );
		$alg            = is_scalar( $alg_option ) ? (string) $alg_option : '';
		$hint           = self::read_secret_option( self::OPTION_CLI_TOKEN_HINT );
		$created_option = get_option( self::OPTION_CLI_TOKEN_CREATED, '' );
		$created        = is_scalar( $created_option ) ? (string) $created_option : '';
		return array(
			'hash'    => $hash,
			'alg'     => $alg,
			'hint'    => $hint,
			'created' => $created,
		);
	}

	public static function set_cli_token( string $hash, string $alg, string $hint, string $created ): void {
		update_option( self::OPTION_CLI_TOKEN_HASH, self::encrypt_secret( $hash ), false );
		update_option( self::OPTION_CLI_TOKEN_ALG, $alg, false );
		update_option( self::OPTION_CLI_TOKEN_HINT, self::encrypt_secret( $hint ), false );
		update_option( self::OPTION_CLI_TOKEN_CREATED, $created, false );
	}

	public static function clear_cli_token(): void {
		delete_option( self::OPTION_CLI_TOKEN_HASH );
		delete_option( self::OPTION_CLI_TOKEN_ALG );
		delete_option( self::OPTION_CLI_TOKEN_HINT );
		delete_option( self::OPTION_CLI_TOKEN_CREATED );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_keepalive_state(): array {
		$raw = get_option( self::OPTION_KEEPALIVE_STATE, array( 'failures' => 0 ) );
		return is_array( $raw ) ? $raw : array( 'failures' => 0 );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public static function set_keepalive_state( array $state ): void {
		update_option( self::OPTION_KEEPALIVE_STATE, $state, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_job_queue(): array {
		$raw = get_option( self::OPTION_JOB_QUEUE, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param array<string, mixed> $queue
	 */
	public static function set_job_queue( array $queue ): void {
		update_option( self::OPTION_JOB_QUEUE, $queue, false );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_job_history(): array {
		$raw = get_option( self::OPTION_JOB_HISTORY, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param array<int, array<string, mixed>> $history
	 */
	public static function set_job_history( array $history ): void {
		update_option( self::OPTION_JOB_HISTORY, $history, false );
	}

	private static function encrypt_secret( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( ! class_exists( '\MUMB_Security' ) ) {
			return $value;
		}
		return \MUMB_Security::encrypt( $value );
	}

	private static function decrypt_secret( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( ! class_exists( '\MUMB_Security' ) ) {
			return $value;
		}
		return \MUMB_Security::decrypt( $value );
	}

	private static function read_secret_option( string $option ): string {
		$raw   = get_option( $option, '' );
		$value = is_string( $raw ) ? $raw : '';
		if ( '' === $value ) {
			return '';
		}
		if ( strncmp( $value, 'enc:', 4 ) === 0 ) {
			return self::decrypt_secret( $value );
		}
		self::migrate_secret_option( $option, $value );
		return $value;
	}

	private static function migrate_secret_option( string $option, string $value ): void {
		if ( '' === $value ) {
			return;
		}
		update_option( $option, self::encrypt_secret( $value ), false );
		self::log_cli_token_migration();
	}

	private static function log_cli_token_migration(): void {
		if ( self::$cli_token_migration_logged ) {
			return;
		}
		self::$cli_token_migration_logged = true;
		if ( class_exists( '\MUMB_Logger' ) ) {
			\MUMB_Logger::log( 'info', 'cli_token_migrated', 'CLI token storage upgraded to encrypted format' );
		}
	}
}
