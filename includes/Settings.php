<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Value object for plugin settings to avoid mixed arrays. */
final class MUMB_Settings {

	public string $host                     = '';
	public int $port                        = 8728;
	public string $username                 = '';
	public string $password                 = '';
	public bool $ssl                        = false;
	public bool $verify_ssl                 = true;
	public int $api_timeout                 = 8;
	public ?string $mirror_authority        = null;
	/**
	 * Always use the User Manager REST API.
	 *
	 * @var bool
	 */
	public bool $um_rest                    = true;
	public ?string $um_rest_base            = null;
	public int $retry_max                   = 3;
	public int $retry_base_ms               = 200;
	public int $retry_jitter_ms             = 100;
	public int $keepalive_jitter_ms         = 500;
	public int $keepalive_failure_threshold = 2;

	private function __construct() {
	}

	/**
	 * Load settings from WordPress option storage.
	 */
	public static function load(): self {
		$instance = new self();
		/** @var array<string, mixed>|false $raw */
		$raw  = get_option( 'mumb_settings', array() );
		$opts = is_array( $raw ) ? $raw : array();

		$instance->host     = ( isset( $opts['host'] ) && is_scalar( $opts['host'] ) ) ? (string) $opts['host'] : '';
		$instance->port     = ( isset( $opts['port'] ) && is_numeric( $opts['port'] ) ) ? (int) $opts['port'] : 8728;
		$instance->username = ( isset( $opts['username'] ) && is_scalar( $opts['username'] ) ) ? (string) $opts['username'] : '';
		if ( isset( $opts['password'] ) && is_scalar( $opts['password'] ) ) {
			$stored_password    = (string) $opts['password'];
			$instance->password = class_exists( 'MUMB_Security' ) ? MUMB_Security::decrypt( $stored_password ) : $stored_password;
		} else {
			$instance->password = '';
		}
		$instance->ssl                         = ! empty( $opts['ssl'] );
		$instance->verify_ssl                  = isset( $opts['verify_ssl'] ) ? (bool) $opts['verify_ssl'] : true;
		$instance->api_timeout                 = ( isset( $opts['api_timeout'] ) && is_numeric( $opts['api_timeout'] ) ) ? (int) $opts['api_timeout'] : 8;
		$instance->mirror_authority            = ( isset( $opts['mirror_authority'] ) && is_scalar( $opts['mirror_authority'] ) ) ? (string) $opts['mirror_authority'] : null;
		$instance->um_rest                     = true; // Always use User Manager
		$instance->um_rest_base                = ( isset( $opts['um_rest_base'] ) && is_scalar( $opts['um_rest_base'] ) ) ? (string) $opts['um_rest_base'] : null;
		$instance->retry_max                   = ( isset( $opts['retry_max'] ) && is_numeric( $opts['retry_max'] ) ) ? (int) $opts['retry_max'] : 3;
		$instance->retry_base_ms               = ( isset( $opts['retry_base_ms'] ) && is_numeric( $opts['retry_base_ms'] ) ) ? (int) $opts['retry_base_ms'] : 200;
		$instance->retry_jitter_ms             = ( isset( $opts['retry_jitter_ms'] ) && is_numeric( $opts['retry_jitter_ms'] ) ) ? (int) $opts['retry_jitter_ms'] : 100;
		$instance->keepalive_jitter_ms         = ( isset( $opts['keepalive_jitter_ms'] ) && is_numeric( $opts['keepalive_jitter_ms'] ) ) ? (int) $opts['keepalive_jitter_ms'] : 500;
		$instance->keepalive_failure_threshold = ( isset( $opts['keepalive_failure_threshold'] ) && is_numeric( $opts['keepalive_failure_threshold'] ) ) ? (int) $opts['keepalive_failure_threshold'] : 2;
		return $instance;
	}

	/**
	 * @param array<string, mixed> $opts
	 */
	public static function from_array( array $opts ): self {
		$instance = new self();
		if ( isset( $opts['host'] ) && is_scalar( $opts['host'] ) ) {
			$instance->host = (string) $opts['host'];
		}
		if ( isset( $opts['port'] ) && is_numeric( $opts['port'] ) ) {
			$instance->port = (int) $opts['port'];
		}
		if ( isset( $opts['username'] ) && is_scalar( $opts['username'] ) ) {
			$instance->username = (string) $opts['username'];
		}
		if ( isset( $opts['password'] ) && is_scalar( $opts['password'] ) ) {
			$instance->password = (string) $opts['password'];
		}
		if ( isset( $opts['ssl'] ) ) {
			$instance->ssl = (bool) $opts['ssl'];
		}
		if ( isset( $opts['verify_ssl'] ) ) {
			$instance->verify_ssl = (bool) $opts['verify_ssl'];
		}
		if ( isset( $opts['api_timeout'] ) && is_numeric( $opts['api_timeout'] ) ) {
			$instance->api_timeout = (int) $opts['api_timeout'];
		}
		if ( isset( $opts['mirror_authority'] ) && is_scalar( $opts['mirror_authority'] ) ) {
			$instance->mirror_authority = (string) $opts['mirror_authority'];
		}
		// Always use User Manager - no toggle needed
		$instance->um_rest = true;
		if ( isset( $opts['um_rest_base'] ) && is_scalar( $opts['um_rest_base'] ) ) {
			$instance->um_rest_base = (string) $opts['um_rest_base'];
		}
		foreach ( array( 'retry_max', 'retry_base_ms', 'retry_jitter_ms', 'keepalive_jitter_ms', 'keepalive_failure_threshold' ) as $key ) {
			if ( isset( $opts[ $key ] ) && is_numeric( $opts[ $key ] ) ) {
				$instance->{$key} = (int) $opts[ $key ];
			}
		}
		return $instance;
	}

	public function decrypted_password(): string {
		return $this->password;
	}

	public function is_configured(): bool {
		return $this->host !== '' && $this->username !== '' && $this->password !== '';
	}

	/**
	 * Export sanitized values for persistence.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array( bool $encrypt_password = true ): array {
		$out = array(
			'host'        => $this->host,
			'port'        => $this->port,
			'username'    => $this->username,
			'password'    => $encrypt_password && class_exists( 'MUMB_Security' ) ? MUMB_Security::encrypt( $this->password ) : $this->password,
			'ssl'         => $this->ssl ? 1 : 0,
			'verify_ssl'  => $this->verify_ssl ? 1 : 0,
			'api_timeout' => $this->api_timeout,
			'um_rest'     => 1, // Always enabled
		);
		if ( $this->mirror_authority !== null ) {
			$out['mirror_authority'] = $this->mirror_authority;
		}
		if ( $this->um_rest_base !== null && $this->um_rest_base !== '' ) {
			$out['um_rest_base'] = $this->um_rest_base;
		}
		$out['retry_max']                   = $this->retry_max;
		$out['retry_base_ms']               = $this->retry_base_ms;
		$out['retry_jitter_ms']             = $this->retry_jitter_ms;
		$out['keepalive_jitter_ms']         = $this->keepalive_jitter_ms;
		$out['keepalive_failure_threshold'] = $this->keepalive_failure_threshold;
		return $out;
	}
}
