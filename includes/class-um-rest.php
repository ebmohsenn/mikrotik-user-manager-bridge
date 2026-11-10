<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUMB_UM_REST {

	protected string $base;
	protected string $user;
	protected string $pass;
	protected bool $verify;
	protected int $timeout;

	public function __construct( string $base_url, string $username, string $password, bool $verify_ssl = true, int $timeout = 8 ) {
		$base_url = rtrim( $base_url, '/' );
		if ( ! preg_match( '#^https?://#i', $base_url ) ) {
			$base_url = 'https://' . $base_url;
		}
		// Normalize: strip any trailing /rest, /rest/userman, or /rest/user-manager so we can append consistently
		$normalized = preg_replace( '#/rest(?:/(?:userman|user-manager))?$#i', '', $base_url );
		if ( ! is_string( $normalized ) ) {
			$normalized = $base_url;
		}
		$this->base    = $normalized;
		$this->user    = $username;
		$this->pass    = $password;
		$this->verify  = $verify_ssl;
		$this->timeout = $timeout > 0 ? $timeout : 8;
		// Use system default CA bundle (no custom ca_path)
	}

	/**
	 * @param array<string, scalar>|null $json
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	protected function req( string $method, string $path, ?array $json = null ): array|WP_Error {
		$url  = $this->base . $path;
		$args = array(
			'method'    => $method,
			'timeout'   => $this->timeout,
			'sslverify' => $this->verify,
			'headers'   => array(
				'Authorization' => 'Basic ' . base64_encode( $this->user . ':' . $this->pass ),
				'Accept'        => 'application/json',
			),
		);
		// Use system default CA bundle (no custom sslcertificates)
		if ( null !== $json ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$encoded                         = wp_json_encode( $json );
			if ( false === $encoded ) {
				$encoded = '[]';
			}
			$args['body'] = $encoded;
		}
		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			if ( $this->verify && class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log(
					'error',
					'router_rest_ssl',
					'REST SSL verification failed',
					array(
						'url'   => $url,
						'error' => $resp->get_error_message(),
					)
				);
			}
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		/** @var mixed $decoded */
		$decoded = '' !== $body ? json_decode( $body, true ) : array();
		/** @var array<array-key, mixed> $data */
		$data = is_array( $decoded ) ? $decoded : array();
		if ( $code < 200 || $code >= 300 ) {
			$msg = 'HTTP ' . $code;
			if ( isset( $data['message'] ) && is_scalar( $data['message'] ) ) {
				$msg .= ': ' . (string) $data['message'];
			}
			return new WP_Error(
				'mumb_um_rest_http',
				$msg,
				array(
					'status' => $code,
					'body'   => $data,
				)
			);
		}
		return $data;
	}

	// Some RouterOS builds expose UM under /rest/user-manager rather than /rest/userman.
	// Try user-manager first, then fallback to userman if needed.
	/**
	 * @param array<string, scalar>|null $json
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	protected function req_with_fallback( string $method, string $suffix, ?array $json = null ): array|WP_Error {
		$prefixes = array( '/rest/user-manager', '/rest/userman' );
		new WP_Error( 'mumb_um_rest_http', 'Unknown REST error' );
		foreach ( $prefixes as $p ) {
			$res = $this->req( $method, $p . $suffix, $json );
			if ( ! is_wp_error( $res ) ) {
				return $res;
			}
			$last_err = $res;
		}
		return $last_err;
	}

	// Users
	/**
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function list_users(): array|WP_Error {
		return $this->req_with_fallback( 'GET', '/user' );
	}
	/**
	 * @param array<string, scalar> $data
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function create_user( array $data ): array|WP_Error {
		return $this->req_with_fallback( 'POST', '/user', $data );
	}
	/**
	 * @param array<string, scalar> $data
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function update_user( string $id, array $data ): array|WP_Error {
		return $this->req_with_fallback( 'PATCH', '/user/' . rawurlencode( $id ), $data );
	}
	/**
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function delete_user( string $id ): array|WP_Error {
		return $this->req_with_fallback( 'DELETE', '/user/' . rawurlencode( $id ) );
	}

	// Groups
	/**
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function list_groups(): array|WP_Error {
		return $this->req_with_fallback( 'GET', '/group' );
	}
	/**
	 * @param array<string, scalar> $data
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function create_group( array $data ): array|WP_Error {
		return $this->req_with_fallback( 'POST', '/group', $data );
	}
	/**
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function delete_group( string $id ): array|WP_Error {
		return $this->req_with_fallback( 'DELETE', '/group/' . rawurlencode( $id ) );
	}
	/**
	 * @param array<string, scalar> $data
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function update_group( string $id, array $data ): array|WP_Error {
		return $this->req_with_fallback( 'PATCH', '/group/' . rawurlencode( $id ), $data );
	}

	// Profiles
	/**
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function list_profiles(): array|WP_Error {
		return $this->req_with_fallback( 'GET', '/profile' );
	}

	/**
	 * Attempt to download an OVPN profile for the provided MikroTik username.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function download_profile_config( string $username, string $type = 'ovpn' ): array|WP_Error {
		$username = trim( $username );
		if ( '' === $username ) {
			return new WP_Error( 'mumb_um_rest_username', __( 'Username required for profile download.', 'mikrotik-user-manager-bridge' ) );
		}
		$suffix = sprintf( '/profile/export?username=%s&type=%s', rawurlencode( $username ), rawurlencode( $type ) );
		$resp   = $this->req_with_fallback( 'GET', $suffix );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		if ( isset( $resp['content'] ) && is_string( $resp['content'] ) ) {
			return $resp;
		}
		if ( isset( $resp['data'] ) && is_string( $resp['data'] ) ) {
			return array( 'content' => $resp['data'] );
		}
		return new WP_Error( 'mumb_um_rest_profile_empty', __( 'The router did not provide a profile export.', 'mikrotik-user-manager-bridge' ) );
	}
	/**
	 * @param array<string, scalar> $data
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function create_profile( array $data ): array|WP_Error {
		return $this->req_with_fallback( 'POST', '/profile', $data );
	}
	/**
	 * @param array<string, scalar> $data
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function update_profile( string $id, array $data ): array|WP_Error {
		return $this->req_with_fallback( 'PATCH', '/profile/' . rawurlencode( $id ), $data );
	}
	/**
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function delete_profile( string $id ): array|WP_Error {
		return $this->req_with_fallback( 'DELETE', '/profile/' . rawurlencode( $id ) );
	}

	// Routers
	/**
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function list_routers(): array|WP_Error {
		return $this->req_with_fallback( 'GET', '/router' );
	}
	/**
	 * @param array<string, scalar> $data
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function create_router( array $data ): array|WP_Error {
		return $this->req_with_fallback( 'POST', '/router', $data );
	}
	/**
	 * @param array<string, scalar> $data
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function update_router( string $id, array $data ): array|WP_Error {
		return $this->req_with_fallback( 'PATCH', '/router/' . rawurlencode( $id ), $data );
	}
	/**
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function delete_router( string $id ): array|WP_Error {
		return $this->req_with_fallback( 'DELETE', '/router/' . rawurlencode( $id ) );
	}

	// User Profiles (assignments) — UM REST fallback
	/**
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function list_user_profiles(): array|WP_Error {
		return $this->req_with_fallback( 'GET', '/user-profile' );
	}
	/**
	 * @param array<string, scalar> $data
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function create_user_profile( array $data ): array|WP_Error {
		return $this->req_with_fallback( 'POST', '/user-profile', $data );
	}
	/**
	 * @return array<string, mixed>|WP_Error
	 * @phpstan-return array<array-key, mixed>|WP_Error
	 */
	public function delete_user_profile( string $id ): array|WP_Error {
		return $this->req_with_fallback( 'DELETE', '/user-profile/' . rawurlencode( $id ) );
	}
}
