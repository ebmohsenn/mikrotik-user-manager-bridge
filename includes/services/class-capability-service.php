<?php

namespace MUMB\Services;

use MUMB_Logger;
use MUMB_RouterOS_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Router capability probing and caching.
 */
class CapabilityService {

	private const STATE_KEY        = 'capabilities';
	private const STATE_CHECKED_AT = 'capabilities_checked_at';

	/**
	 * Return cached capabilities (probing if necessary).
	 *
	 * @return array<string, mixed>
	 */
	public function get_capabilities( bool $force_refresh = false ): array {
		$state = SettingsService::get_connection_state();
		$caps  = isset( $state[ self::STATE_KEY ] ) && is_array( $state[ self::STATE_KEY ] ) ? $state[ self::STATE_KEY ] : null;
		if ( $caps && ! $force_refresh ) {
			return $caps;
		}
		$caps    = $this->probe();
		$payload = array(
			self::STATE_KEY        => $caps,
			self::STATE_CHECKED_AT => current_time( 'mysql' ),
		);
		SettingsService::update_connection_state( $payload );
		return $caps;
	}

	/**
	 * Force a reprobe and persist the results.
	 *
	 * @return array<string, mixed>
	 */
	public function probe(): array {
		$credentials = SettingsService::get_router_credentials();
		if ( empty( $credentials ) ) {
			$caps = $this->defaults( 'Missing credentials' );
			SettingsService::update_connection_state( array( self::STATE_KEY => $caps ) );
			return $caps;
		}

		$result                                  = $this->defaults();
		$binary_probe                            = $this->probe_binary( $credentials );
		$result['binary']                        = $binary_probe;
		$rest_probe                              = $this->probe_rest( $credentials );
		$result['rest']                          = $rest_probe['rest'];
		$result['profile_updates']['rest']       = $rest_probe['rest_profile_updates'];
			$binary_available                    = ! empty( $binary_probe['available'] );
			$result['profile_updates']['binary'] = $binary_available && $this->binary_supports_profile_update( $credentials );
			$user_profile_field                  = $rest_probe['user_profile_field'] ?? ( $result['quirks']['user_profile_field'] ?? 'user' );
		if ( ! is_string( $user_profile_field ) || '' === $user_profile_field ) {
			$user_profile_field = 'user';
		}
			$result['quirks']['user_profile_field'] = $user_profile_field;

		if ( class_exists( 'MUMB_Logger' ) ) {
			MUMB_Logger::log( 'info', 'capabilities', 'Capability probe completed', $result );
		}

		SettingsService::update_connection_state(
			array(
				self::STATE_KEY        => $result,
				self::STATE_CHECKED_AT => current_time( 'mysql' ),
			)
		);
		return $result;
	}

	public function supports_rest(): bool {
		$caps = $this->get_capabilities();
		return is_array( $caps ) && isset( $caps['rest'] ) && is_array( $caps['rest'] ) && ! empty( $caps['rest']['available'] );
	}

	public function supports_profile_update(): bool {
		$caps           = $this->get_capabilities();
		$rest_support   = is_array( $caps ) && isset( $caps['profile_updates'] ) && is_array( $caps['profile_updates'] ) && ! empty( $caps['profile_updates']['rest'] );
		$binary_support = is_array( $caps ) && isset( $caps['profile_updates'] ) && is_array( $caps['profile_updates'] ) && ! empty( $caps['profile_updates']['binary'] );
		return $rest_support || $binary_support;
	}

	public function supports_dual_namespace(): bool {
		$caps = $this->get_capabilities();
		return is_array( $caps ) && isset( $caps['binary'] ) && is_array( $caps['binary'] ) && ! empty( $caps['binary']['dual_namespace'] );
	}

	/**
	 * @return array{reason: string, binary: array<string, mixed>, rest: array<string, mixed>, profile_updates: array<string, mixed>, quirks: array<string, mixed>}
	 */
	private function defaults( string $reason = '' ): array {
		return array(
			'reason'          => $reason,
			'binary'          => array(
				'available'      => false,
				'dual_namespace' => false,
				'error'          => $reason,
			),
			'rest'            => array(
				'configured' => false,
				'available'  => false,
				'base_path'  => null,
				'error'      => $reason,
			),
			'profile_updates' => array(
				'binary' => false,
				'rest'   => false,
			),
			'quirks'          => array(
				'user_profile_field' => 'user',
			),
		);
	}

	/**
	 * @param array<string, mixed> $credentials
	 * @return array<string, mixed>
	 */
	private function probe_binary( array $credentials ): array {
		$info = array(
			'available'      => false,
			'dual_namespace' => false,
			'error'          => null,
		);
		try {
			$api  = new MUMB_RouterOS_API(
				isset( $credentials['host'] ) && is_scalar( $credentials['host'] ) ? (string) $credentials['host'] : '',
				isset( $credentials['port'] ) && is_numeric( $credentials['port'] ) ? (int) $credentials['port'] : 8728,
				isset( $credentials['username'] ) && is_scalar( $credentials['username'] ) ? (string) $credentials['username'] : '',
				isset( $credentials['password'] ) && is_scalar( $credentials['password'] ) ? (string) $credentials['password'] : '',
				(bool) ( $credentials['ssl'] ?? false ),
				isset( $credentials['api_timeout'] ) && is_numeric( $credentials['api_timeout'] ) ? (int) $credentials['api_timeout'] : 8,
				isset( $credentials['verify_ssl'] ) ? (bool) $credentials['verify_ssl'] : true
			);
			$test = $api->test();
			if ( empty( $test['success'] ) ) {
				$info['error'] = isset( $test['message'] ) ? $test['message'] : 'Binary test failed';
				return $info;
			}
			$info['available'] = true;

			$primary                = $api->request( array( '/user-manager/user/print', '=.proplist=name' ) );
			$legacy                 = $api->request( array( '/tool/user-manager/user/print', '=.proplist=login' ) );
			$info['dual_namespace'] = ! empty( $primary['success'] ) && ! empty( $legacy['success'] );
		} catch ( \Throwable $e ) {
			$info['error'] = $e->getMessage();
		}
		return $info;
	}

	/**
	 * @param array<string, mixed> $credentials
	 * @return array{rest: array<string, mixed>, rest_profile_updates: bool, user_profile_field?: string}
	 */
	private function probe_rest( array $credentials ): array {
		$rest_info = array(
			'rest'                 => array(
				'configured' => ! empty( $credentials['um_rest_base'] ),
				'available'  => false,
				'base_path'  => null,
				'error'      => null,
			),
			'rest_profile_updates' => false,
		);

		if ( empty( $credentials['um_rest'] ) || empty( $credentials['um_rest_base'] ) ) {
			$rest_info['rest']['error'] = 'UM REST disabled in settings';
			return $rest_info;
		}

		$base                            = $this->normalise_rest_base( is_string( $credentials['um_rest_base'] ) ? $credentials['um_rest_base'] : '' );
		$rest_info['rest']['configured'] = true;

		$user   = isset( $credentials['username'] ) && is_scalar( $credentials['username'] ) ? (string) $credentials['username'] : '';
		$pass   = isset( $credentials['password'] ) && is_scalar( $credentials['password'] ) ? (string) $credentials['password'] : '';
		$verify = isset( $credentials['verify_ssl'] ) ? (bool) $credentials['verify_ssl'] : true;

		$paths = array( '/rest/user-manager', '/rest/userman' );
		foreach ( $paths as $path ) {
			$resp = $this->remote_get( $base . $path . '/user', $user, $pass, $verify );
			if ( $resp['ok'] ) {
				$rest_info['rest']['available']    = true;
				$rest_info['rest']['base_path']    = $path;
				$rest_info['rest_profile_updates'] = $this->probe_rest_profile_endpoint( $base . $path, $user, $pass, $verify );
				$rest_info['user_profile_field']   = $resp['user_field'] ?? 'user';
				return $rest_info;
			}
			$rest_info['rest']['error'] = $resp['error'] ?? 'Unknown error';
		}

		return $rest_info;
	}

	/**
	 * @param array<string, mixed> $credentials
	 */
	private function binary_supports_profile_update( array $credentials ): bool {
		try {
			$host       = isset( $credentials['host'] ) && is_string( $credentials['host'] ) ? $credentials['host'] : '';
			$port       = isset( $credentials['port'] ) && is_int( $credentials['port'] ) ? $credentials['port'] : 8728;
			$username   = isset( $credentials['username'] ) && is_string( $credentials['username'] ) ? $credentials['username'] : '';
			$password   = isset( $credentials['password'] ) && is_string( $credentials['password'] ) ? $credentials['password'] : '';
			$ssl        = isset( $credentials['ssl'] ) && is_bool( $credentials['ssl'] ) ? $credentials['ssl'] : false;
			$timeout    = isset( $credentials['api_timeout'] ) && is_int( $credentials['api_timeout'] ) ? $credentials['api_timeout'] : 8;
			$verify_ssl = isset( $credentials['verify_ssl'] ) ? (bool) $credentials['verify_ssl'] : true;

			$api  = new MUMB_RouterOS_API( $host, $port, $username, $password, $ssl, $timeout, $verify_ssl );
			$resp = $api->request( array( '/tool/user-manager/user-profile/print', '=.proplist=user,profile' ) );
			return ! empty( $resp['success'] );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'debug', 'capabilities', 'Binary profile probe failed', array( 'error' => $e->getMessage() ) );
			}
			return false;
		}
	}

	private function normalise_rest_base( string $base ): string {
		$base = rtrim( $base, '/' );
		if ( ! preg_match( '#^https?://#i', $base ) ) {
			$base = 'https://' . $base;
		}
		$normalized = preg_replace( '#/rest(?:/(?:userman|user-manager))?$#i', '', $base );
		if ( is_string( $normalized ) ) {
			return $normalized;
		}
		return $base;
	}

	/**
	 * @return array{ok:bool, error?:string, user_field?:string}
	 */
	private function remote_get( string $url, string $user, string $pass, bool $verify ): array {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return array(
				'ok'    => false,
				'error' => 'HTTP API unavailable',
			);
		}
		$args = array(
			'method'    => 'GET',
			'timeout'   => 10,
			'sslverify' => $verify,
			'headers'   => array(
				'Authorization' => 'Basic ' . base64_encode( $user . ':' . $pass ),
				'Accept'        => 'application/json',
			),
		);
		// Use system default CA bundle
		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return array(
				'ok'    => false,
				'error' => $resp->get_error_message(),
			);
		}
		/** @psalm-suppress DocblockTypeContradiction */
		if ( ! is_array( $resp ) ) {
			return array(
				'ok'    => false,
				'error' => 'Invalid HTTP response',
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'ok'    => false,
				'error' => 'HTTP ' . $code,
			);
		}
		$body = wp_remote_retrieve_body( $resp );
		/** @psalm-suppress DocblockTypeContradiction */
		if ( ! is_string( $body ) ) {
			return array(
				'ok'    => false,
				'error' => 'Invalid response body',
			);
		}
		/** @var array<array-key, mixed> $data */
		$data = array();
		if ( '' !== $body ) {
			/** @var mixed $decoded */
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				$data = $decoded;
			}
		}
		$user_field = 'user';
		$first      = null;
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			$first = $data[0];
		} elseif ( isset( $data['data'] ) && is_array( $data['data'] ) && isset( $data['data'][0] ) && is_array( $data['data'][0] ) ) {
			$first = $data['data'][0];
		}
		if ( is_array( $first ) ) {
			if ( array_key_exists( 'users', $first ) ) {
				$user_field = 'users';
			} elseif ( array_key_exists( 'user', $first ) ) {
				$user_field = 'user';
			}
		}
		return array(
			'ok'         => true,
			'user_field' => $user_field,
		);
	}

	private function probe_rest_profile_endpoint( string $base, string $user, string $pass, bool $verify ): bool {
		$url  = $base . '/user-profile';
		$resp = $this->remote_get( $url, $user, $pass, $verify );
		return $resp['ok'];
	}
}
