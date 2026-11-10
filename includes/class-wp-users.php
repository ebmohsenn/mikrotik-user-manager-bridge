<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUMB_WP_Users {

	/**
	 * @param array{
	 *     display_name?: string,
	 *     role?: string,
	 *     user_email?: string,
	 *     password?: string,
	 *     group?: string|int,
	 *     comment?: string,
	 *     shared-users?: int|string,
	 *     disabled?: bool|int|string,
	 *     profile?: string
	 * } $data
	 */
	public static function ensure_user( string $username, array $data = array() ): int {
		if ( '' === $username ) {
			return 0;
		}
		$user    = get_user_by( 'login', $username );
		$user_id = $user instanceof WP_User ? $user->ID : 0;
		$role    = 'customer';
		if ( isset( $data['role'] ) && $data['role'] !== '' ) {
			$role_candidate = sanitize_key( $data['role'] );
			if ( '' !== $role_candidate ) {
				$role = $role_candidate;
			}
		}
		/** @var array<string, mixed> $payload */
		$payload = array(
			'user_login'   => $username,
			'display_name' => isset( $data['display_name'] ) && $data['display_name'] !== '' ? sanitize_text_field( $data['display_name'] ) : $username,
			'role'         => $role,
		);
		if ( isset( $data['user_email'] ) && $data['user_email'] !== '' && is_email( $data['user_email'] ) ) {
			$payload['user_email'] = $data['user_email'];
		}
		if ( isset( $data['password'] ) && $data['password'] !== '' ) {
			$payload['user_pass'] = $data['password'];
		}

		if ( $user_id ) {
			$payload['ID'] = $user_id;
			// Avoid resetting role to blank; WP keeps role if not provided
			unset( $payload['role'] );
			wp_update_user( $payload );
		} else {
			// Ensure required fields
			if ( ! isset( $payload['user_pass'] ) || $payload['user_pass'] === '' ) {
				$payload['user_pass'] = wp_generate_password( 12, true );
			}
			/** @var array{user_login:string, display_name:string, role:string, user_email?:string, user_pass?:string} $payload */
			$inserted = wp_insert_user( $payload );
			if ( is_wp_error( $inserted ) ) {
				return 0;
			}
			if ( ! is_int( $inserted ) ) {
				return 0;
			}
			$user_id = $inserted;
		}
		if ( $user_id > 0 ) {
			// Store plugin-related meta for reference/integration
			if ( isset( $data['group'] ) ) {
				update_user_meta( $user_id, 'mumb_group', sanitize_text_field( (string) $data['group'] ) );
			}
			if ( isset( $data['comment'] ) ) {
				update_user_meta( $user_id, 'mumb_comment', sanitize_text_field( $data['comment'] ) );
			}
			if ( isset( $data['shared-users'] ) ) {
				$shared_raw = $data['shared-users'];
				$shared     = is_numeric( $shared_raw ) ? (int) $shared_raw : 0;
				update_user_meta( $user_id, 'mumb_shared_users', $shared );
			}
			if ( isset( $data['disabled'] ) ) {
				$disabled_raw  = $data['disabled'];
				$disabled_flag = filter_var( $disabled_raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE );
				if ( null === $disabled_flag ) {
					$disabled_flag = (bool) $disabled_raw;
				}
				update_user_meta( $user_id, 'mumb_disabled', $disabled_flag ? 1 : 0 );
			}
			if ( isset( $data['profile'] ) ) {
				update_user_meta( $user_id, 'mumb_profile', sanitize_text_field( $data['profile'] ) );
			}
		}
		return $user_id;
	}

	public static function delete_user( string $username ): bool {
		if ( '' === $username ) {
			return false;
		}
		$user = get_user_by( 'login', $username );
		if ( ! $user ) {
			return false;
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			if ( defined( 'ABSPATH' ) ) {
				/** @var mixed $base_path */
				$base_path = ABSPATH;
					/** @psalm-suppress UnresolvableInclude */
					require_once $base_path . 'wp-admin/includes/user.php';
			}
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			return false;
		}
		$user_id = $user->ID;
		if ( ! is_int( $user_id ) ) {
			$user_id = (int) $user_id;
		}
		if ( $user_id <= 0 ) {
			return false;
		}
		return wp_delete_user( $user_id ) !== false;
	}
}
