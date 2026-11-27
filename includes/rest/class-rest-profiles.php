<?php

namespace MUMB\REST;

use MikroTik_API;
use MUMB_Logger;
use MUMB_Sync_Service;
use WP_REST_Request;
use WP_REST_Response;

class Profiles_Controller {

	public static function register_routes(): void {
		register_rest_route(
			'mwp/v1',
			'/profiles',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_profiles' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/profiles',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_profile' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/profiles/(?P<id>[^/]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_profile' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/profiles/(?P<id>[^/]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_profile' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function list_profiles( WP_REST_Request $req ): array|WP_REST_Response {
		/** @var MikroTik_API|null $api */
		$api = \MWP_REST::build_api();
		if ( $api instanceof MikroTik_API ) {
			try {
				/** @var array<string, mixed> $response */
				$response = $api->get_profiles();
				if ( \MWP_REST::is_success( $response ) ) {
					/** @var array<int, array<string, mixed>> $data */
					$data = array();
					if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
						$filtered_rows = array_filter(
							$response['data'],
							static function ( $item ): bool {
								return is_array( $item );
							}
						);
						/** @var array<int, array<string, mixed>> $clean_rows */
						$clean_rows = array_map(
							static function ( array $item ): array {
								return $item;
							},
							$filtered_rows
						);
						$data       = array_values( $clean_rows );
					}
					return array(
						'success' => true,
						'data'    => $data,
					);
				}
			} catch ( \Throwable $e ) {
				if ( class_exists( 'MUMB_Logger' ) ) {
					MUMB_Logger::log( 'warning', 'profiles_list', $e->getMessage() );
				}
			}
		}

		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Database unavailable' ),
					'data'    => array(),
				),
				500
			);
		}
		$p = $wpdb->prefix;
		$table_name = $wpdb->prefix . 'mikrotik_profiles';
		/** @var string $query */
		$query = $wpdb->prepare( 'SELECT name, validity, updated_at FROM %i ORDER BY name ASC', $table_name );
		/** @var array<int, array<string, mixed>>|null $rows */
		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( is_array( $rows ) && ! empty( $rows ) ) {
			return array(
				'success' => true,
				'data'    => $rows,
			);
		}

		try {
			( new MUMB_Sync_Service() )->sync_profiles();
		} catch ( \Throwable $e ) {
			// ignore sync failures here
		}

		/** @var string|false $sql */
		$sql = $wpdb->prepare( 'SELECT name, validity, updated_at FROM %i ORDER BY name ASC', $p . 'mikrotik_profiles' );
		/** @var array<int, array<string, mixed>>|null $rows */
		$rows = null;
		if ( is_string( $sql ) ) {
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}
		return array(
			'success' => true,
			'data'    => is_array( $rows ) ? $rows : array(),
		);
	}

	public static function create_profile( WP_REST_Request $req ): array|WP_REST_Response {
		/** @var MikroTik_API|null $api */
		$api = \MWP_REST::build_api();
		if ( ! ( $api instanceof MikroTik_API ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Not configured' ),
				),
				400
			);
		}

		/** @var array<string, mixed> $payload */
		$payload = \MWP_REST::sanitize_assoc_payload( $req->get_json_params() );

		// Validate required profile fields
		if ( empty( $payload['name'] ) || ! is_string( $payload['name'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'profile name is required' ),
				),
				400
			);
		}

		// Validate profile name format
		$profile_name = $payload['name'];
		if ( ! preg_match( '/^[a-zA-Z0-9_\-\.]+$/', $profile_name ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'profile name contains invalid characters' ),
				),
				400
			);
		}

		/** @var array<string, mixed> $response */
		$response = $api->create_profile( $payload );
		if ( ! \MWP_REST::is_success( $response ) ) {
			$msg = \MWP_REST::normalize_error_message( $response, 'Failed to create profile' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $msg,
				),
				500
			);
		}

		try {
			( new MUMB_Sync_Service() )->sync_profiles();
		} catch ( \Throwable $e ) {
			// ignore sync failures here
		}

		return array( 'success' => true );
	}

	public static function update_profile( WP_REST_Request $req ): array|WP_REST_Response {
		/** @var mixed $id_param */
		$id_param = $req->get_param( 'id' );
		$id       = sanitize_text_field( is_scalar( $id_param ) ? (string) $id_param : '' );
		if ( '' === $id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Missing profile id' ),
				),
				400
			);
		}

		/** @var MikroTik_API|null $api */
		$api = \MWP_REST::build_api();
		if ( ! ( $api instanceof MikroTik_API ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Not configured' ),
				),
				400
			);
		}

		/** @var array<string, mixed> $payload */
		$payload = \MWP_REST::sanitize_assoc_payload( $req->get_json_params() );
		/** @var array<string, mixed> $response */
		$response = $api->update_profile( $id, $payload );
		if ( ! \MWP_REST::is_success( $response ) ) {
			$msg = \MWP_REST::normalize_error_message( $response, 'Failed to update profile' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $msg,
				),
				500
			);
		}

		try {
			( new MUMB_Sync_Service() )->sync_profiles();
		} catch ( \Throwable $e ) {
			// ignore sync failures here
		}

		return array( 'success' => true );
	}

	public static function delete_profile( WP_REST_Request $req ): array|WP_REST_Response {
		/** @var MikroTik_API|null $api */
		$api = \MWP_REST::build_api();
		if ( ! ( $api instanceof MikroTik_API ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Not configured' ),
				),
				400
			);
		}

		/** @var mixed $id_param */
		$id_param = $req->get_param( 'id' );
		$id       = sanitize_text_field( is_scalar( $id_param ) ? (string) $id_param : '' );
		if ( '' === $id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Missing profile id' ),
				),
				400
			);
		}
		/** @var array<string, mixed> $response */
		$response = $api->delete_profile( $id );
		if ( ! \MWP_REST::is_success( $response ) ) {
			$msg = \MWP_REST::normalize_error_message( $response, 'Failed to delete profile' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $msg,
				),
				500
			);
		}

		try {
			( new MUMB_Sync_Service() )->sync_profiles();
		} catch ( \Throwable $e ) {
			// ignore sync failures here
		}

		return array( 'success' => true );
	}

	private static function msg( string $text ): string {
		return \__( $text, 'mikrotik-user-manager-bridge' );
	}
}
