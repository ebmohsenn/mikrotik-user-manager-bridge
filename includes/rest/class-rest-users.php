<?php

namespace MUMB\REST;

use MUMB_Affiliates;
use MUMB_Logger;
use MUMB_Queue;
use MUMB_Sync_Service;
use MUMB_WP_Users;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Users_Controller {

	private const CACHE_KEY = 'mwp_rest_users';
	private const CACHE_TTL = 30;

	public static function register_routes(): void {
		register_rest_route(
			'mwp/v1',
			'/users',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_users' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mikrotik/v1',
			'/users',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_users' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/users',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_user' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/users/(?P<id>[^/]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_user' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/users/(?P<id>[^/]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_user' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mikrotik/v1',
			'/users',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_user' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mikrotik/v1',
			'/users/(?P<id>[^/]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_user' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mikrotik/v1',
			'/users/(?P<id>[^/]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_user' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function list_users( WP_REST_Request $req ): array|WP_REST_Response {
		/** @var mixed $force_param */
		$force_param = $req->get_param( 'force' );
		$force       = is_scalar( $force_param ) ? (bool) $force_param : false;

		/** @var mixed $page_param */
		$page_param = $req->get_param( 'page' );
		$page       = is_numeric( $page_param ) ? (int) $page_param : 1;
		if ( $page < 1 ) {
			$page = 1;
		}

		/** @var mixed $per_page_param */
		$per_page_param = $req->get_param( 'per_page' );
		$per_page       = is_numeric( $per_page_param ) ? (int) $per_page_param : 50;
		if ( $per_page < 1 ) {
			$per_page = 1;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		/** @var mixed $search_param */
		$search_param = $req->get_param( 'search' );
		$search       = sanitize_text_field( is_scalar( $search_param ) ? (string) $search_param : '' );

		/** @var mixed $affiliate_param */
		$affiliate_param = $req->get_param( 'affiliate_owner' );
		$affiliate_owner = 0;
		if ( null !== $affiliate_param && '' !== $affiliate_param ) {
			if ( is_numeric( $affiliate_param ) ) {
				$affiliate_owner = (int) $affiliate_param;
				if ( $affiliate_owner < 0 ) {
					$affiliate_owner = 0;
				}
			} else {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => self::msg( 'Invalid affiliate owner parameter' ),
					),
					400
				);
			}
		}

		$cache_key = self::CACHE_KEY . ':' . md5( (string) json_encode( array( $search, $affiliate_owner ) ) );

		if ( ! $force ) {
			/** @var mixed $cached */
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
				$cached_data = array();
				/** @var mixed $row */
				foreach ( $cached['data'] as $row ) {
					if ( is_array( $row ) ) {
						$cached_data[] = $row;
					}
				}
				$cached_payload = array(
					'success' => (bool) ( $cached['success'] ?? true ),
					'data'    => $cached_data,
				);
				return self::paginate_payload( $cached_payload, $page, $per_page );
			}
		}

		if ( $affiliate_owner > 0 ) {
			$owner_user = get_user_by( 'id', $affiliate_owner );
			if (
				! $owner_user instanceof WP_User || ( class_exists( 'MUMB_Affiliates' ) && !
				MUMB_Affiliates::is_affiliate_user( $owner_user ) )
			) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => self::msg( 'Invalid affiliate owner' ),
					),
					400
				);
			}
		}

		// Read from WordPress database instead of live API (more performant and reliable)
		global $wpdb;
		$table = $wpdb->prefix . 'mikrotik_users';
		
		$where_clauses = array();
		$where_params = array();
		
		if ( '' !== $search ) {
			$where_clauses[] = '(username LIKE %s OR comment LIKE %s OR profile LIKE %s)';
			$search_like = '%' . $wpdb->esc_like( $search ) . '%';
			$where_params[] = $search_like;
			$where_params[] = $search_like;
			$where_params[] = $search_like;
		}
		
		if ( $affiliate_owner > 0 ) {
			$where_clauses[] = 'affiliate_id = %d';
			$where_params[] = $affiliate_owner;
		}
		
		$where_sql = ! empty( $where_clauses ) ? ' WHERE ' . implode( ' AND ', $where_clauses ) : '';
		
		$query = "SELECT * FROM `{$table}`{$where_sql} ORDER BY username ASC";
		
		if ( ! empty( $where_params ) ) {
			$query = $wpdb->prepare( $query, ...$where_params );
		}
		
		/** @var array<int, array<string, mixed>>|null $db_rows */
		$db_rows = $wpdb->get_results( $query, ARRAY_A );
		
		$data = array();
		if ( is_array( $db_rows ) ) {
			foreach ( $db_rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				// Convert database row to API format
				$user_data = array(
					'id' => $row['id'] ?? null,
					'username' => $row['username'] ?? '',
					'password' => '***', // Don't expose passwords
					'profile' => $row['profile'] ?? '',
					'service' => $row['service'] ?? 'any',
					'local_address' => $row['local_address'] ?? '',
					'remote_address' => $row['remote_address'] ?? '',
					'caller_id' => $row['caller_id'] ?? '',
					'disabled' => ! empty( $row['disabled'] ),
					'comment' => $row['comment'] ?? '',
					'router_id' => $row['router_id'] ?? 'iran',
					'created_at' => $row['created_at'] ?? null,
					'updated_at' => $row['updated_at'] ?? null,
				);
				$data[] = $user_data;
			}
		}

		// Search already handled by SQL WHERE clause above
		
		if ( ! empty( $data ) ) {
			$usernames = array();
			foreach ( $data as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				// Database always returns 'username' field
				/** @var mixed $username_val */
				$username_val = $row['username'] ?? '';
				$username = is_scalar( $username_val ) ? strtolower( (string) $username_val ) : '';
				if ( '' !== $username ) {
					$usernames[] = $username;
				}
			}
			$usernames = array_values( array_unique( $usernames ) );
			if ( ! empty( $usernames ) ) {
				$wp_users    = get_users(
					array(
						'login__in' => $usernames,
					)
				);
				$wp_user_map = array();
				foreach ( $wp_users as $wp_user ) {
					if ( ! $wp_user instanceof WP_User ) {
						continue;
					}
					$key = strtolower( $wp_user->user_login );
					/** @var mixed $owner_meta */
					$owner_meta = get_user_meta( $wp_user->ID, 'mumb_affiliate_owner', true );
					$owner_id   = is_scalar( $owner_meta ) ? (int) $owner_meta : 0;
					$owner_info = null;
					if ( $owner_id > 0 ) {
						$owner = get_user_by( 'id', $owner_id );
						if ( $owner instanceof WP_User ) {
							$owner_info = array(
								'id'    => $owner->ID,
								'name'  => $owner->display_name ?: $owner->user_login,
								'email' => $owner->user_email,
								'login' => $owner->user_login,
							);
						}
					}
					$wp_user_map[ $key ] = array(
						'affiliate_owner' => $owner_info,
					);
				}
				if ( ! empty( $wp_user_map ) ) {
					foreach ( $data as $index => $row ) {
						if ( ! is_array( $row ) ) {
							continue;
						}
						// Database always returns 'username' field
						/** @var mixed $username_val */
						$username_val = $row['username'] ?? '';
						$lookup = is_scalar( $username_val ) ? strtolower( (string) $username_val ) : '';
						if ( '' !== $lookup && isset( $wp_user_map[ $lookup ] ) ) {
							$data[ $index ]['affiliate_owner'] = $wp_user_map[ $lookup ]['affiliate_owner'];
						}
					}
				}
			}
		}

		$payload = array(
			'success' => true,
			'data'    => $data,
		);
		set_transient( $cache_key, $payload, self::CACHE_TTL );
		return self::paginate_payload( $payload, $page, $per_page );
	}

	public static function create_user( WP_REST_Request $req ): array|WP_REST_Response {
		/** @var mixed $username_param */
		$username_param = $req->get_param( 'username' );
		$username       = sanitize_user( is_scalar( $username_param ) ? (string) $username_param : '', true );
		/** @var mixed $password_param */
		$password_param = $req->get_param( 'password' );
		$password       = is_scalar( $password_param ) ? (string) $password_param : '';
		/** @var mixed $group_param */
		$group_param = $req->get_param( 'group' );
		$group       = sanitize_text_field( is_scalar( $group_param ) ? (string) $group_param : '' );
		/** @var mixed $profile_param */
		$profile_param = $req->get_param( 'profile' );
		$profile       = sanitize_text_field( is_scalar( $profile_param ) ? (string) $profile_param : '' );
		/** @var mixed $comment_param */
		$comment_param = $req->get_param( 'comment' );
		$comment       = sanitize_textarea_field( is_scalar( $comment_param ) ? (string) $comment_param : '' );

		// Enhanced parameter validation
		if ( '' === $username || '' === $password ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'username and password are required' ),
				),
				400
			);
		}

		// Validate username format
		if ( strlen( $username ) < 3 || strlen( $username ) > 64 ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'username must be between 3 and 64 characters' ),
				),
				400
			);
		}

		// Validate password strength
		if ( strlen( $password ) < 6 ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'password must be at least 6 characters' ),
				),
				400
			);
		}

		// Validate group parameter if provided
		if ( '' !== $group && ! preg_match( '/^[a-zA-Z0-9_\-\.]+$/', $group ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'group name contains invalid characters' ),
				),
				400
			);
		}

		// Validate profile parameter if provided
		if ( '' !== $profile && ! preg_match( '/^[a-zA-Z0-9_\-\.]+$/', $profile ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'profile name contains invalid characters' ),
				),
				400
			);
		}

		/** @var mixed $owner_param */
		$owner_param = $req->get_param( 'affiliate_owner' );
		/** @var int|null $affiliate_owner */
		$affiliate_owner = null;
		if ( null !== $owner_param && '' !== $owner_param ) {
			if ( is_numeric( $owner_param ) ) {
				$affiliate_owner = (int) $owner_param;
			}
		} else {
			// Determine affiliate owner
			/** @var WP_User|false $current_user */
			$current_user = wp_get_current_user();
			if ( $current_user instanceof WP_User && MUMB_Affiliates::is_affiliate_user( $current_user ) ) {
				$affiliate_owner = $current_user->ID;
			} else {
				// Default to admin (ID 1) for self-registration or admin-created users
				$affiliate_owner = 1;
			}
		}
		if ( is_int( $affiliate_owner ) && $affiliate_owner > 0 ) {
			$owner_user = get_user_by( 'id', $affiliate_owner );
			if ( ! $owner_user instanceof WP_User || ( class_exists( 'MUMB_Affiliates' ) && ! MUMB_Affiliates::is_affiliate_user( $owner_user ) ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => self::msg( 'Invalid affiliate owner' ),
					),
					400
				);
			}
		}

		$api = \MWP_REST::build_api();
		if ( ! $api ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Not configured' ),
				),
				400
			);
		}

		try {
			$payload = array(
				'username' => $username,
				'password' => $password,
			);
			if ( '' !== $group ) {
				$payload['group'] = $group;
			}
			if ( '' !== $comment ) {
				$payload['comment'] = $comment;
			}
			/** @var mixed $shared_param */
			$shared_param = $req->get_param( 'shared-users' );
			if ( is_scalar( $shared_param ) && '' !== $shared_param ) {
				if ( is_numeric( $shared_param ) ) {
					$payload['shared-users'] = (int) $shared_param;
				}
			}
			/** @var mixed $disabled_param */
			$disabled_param = $req->get_param( 'disabled' );
			if ( is_scalar( $disabled_param ) ) {
				$payload['disabled'] = (bool) $disabled_param;
			}

			$res = $api->add_user( $payload );
			if ( ! \MWP_REST::is_success( $res ) ) {
				$msg = \MWP_REST::normalize_error_message( $res, 'Failed to create user' );
				throw new \RuntimeException( $msg );
			}

			if ( '' !== $profile ) {
				MUMB_Queue::enqueue(
					'assign_profile:' . $username,
					array(
						'type'    => 'assign_profile',
						'user'    => $username,
						'profile' => $profile,
					)
				);
				try {
					MUMB_Queue::process();
				} catch ( \Throwable $e ) {
					// ignore queue processing errors during best-effort processing
				}
			}

			$wp_user_id = 0;
			if ( class_exists( 'MUMB_WP_Users' ) ) {
				$user_payload = array(
					'password' => $password,
					'group'    => $group,
					'comment'  => $comment,
					'profile'  => $profile,
					'role'     => 'customer',
				);
				if ( isset( $payload['shared-users'] ) ) {
					$user_payload['shared-users'] = $payload['shared-users'];
				}
				if ( isset( $payload['disabled'] ) ) {
					$user_payload['disabled'] = ! empty( $payload['disabled'] ) ? 1 : 0;
				}
				$wp_user_id = MUMB_WP_Users::ensure_user( $username, $user_payload );
			}

			if ( $wp_user_id > 0 ) {
				if ( is_int( $affiliate_owner ) && $affiliate_owner > 0 ) {
					update_user_meta( $wp_user_id, 'mumb_affiliate_owner', $affiliate_owner );
					update_user_meta( $wp_user_id, 'created_by_affiliate', $affiliate_owner );
				}
			} elseif ( is_int( $affiliate_owner ) && $affiliate_owner > 0 ) {
				$wp_user = get_user_by( 'login', $username );
				if ( $wp_user instanceof WP_User ) {
					update_user_meta( $wp_user->ID, 'mumb_affiliate_owner', $affiliate_owner );
					update_user_meta( $wp_user->ID, 'created_by_affiliate', $affiliate_owner );
				}
			}

			( new MUMB_Sync_Service() )->sync_all();
			self::flush_cache();
			return array( 'success' => true );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'error', 'rest_users_create', $e->getMessage(), array( 'username' => $username ) );
			}
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * Update user information
	 *
	 * @return array<string, mixed>|WP_REST_Response
	 */
	public static function update_user( WP_REST_Request $req ): array|WP_REST_Response {
		/** @var mixed $id_param */
		$id_param = $req->get_param( 'id' );
		$id       = sanitize_text_field( is_scalar( $id_param ) ? (string) $id_param : '' );
		/** @var mixed $pass_param */
		$pass_param = $req->get_param( 'password' );
		$new_pass   = is_scalar( $pass_param ) ? (string) $pass_param : '';
		/** @var mixed $group_param */
		$group_param = $req->get_param( 'group' );
		$new_group   = sanitize_text_field( is_scalar( $group_param ) ? (string) $group_param : '' );
		/** @var mixed $comment_param */
		$comment_param = $req->get_param( 'comment' );
		$new_comment   = sanitize_textarea_field( is_scalar( $comment_param ) ? (string) $comment_param : '' );
		/** @var mixed $profile_param */
		$profile_param  = $req->get_param( 'profile' );
		$assign_profile = sanitize_text_field( is_scalar( $profile_param ) ? (string) $profile_param : '' );
		/** @var mixed $shared_param */
		$shared_param = $req->get_param( 'shared-users' );
		/** @var mixed $disabled_param */
		$disabled_param = $req->get_param( 'disabled' );
		/** @var mixed $affiliate_param */
		$affiliate_param          = $req->get_param( 'affiliate_owner' );
		$affiliate_owner_provided = false;
		$affiliate_owner          = 0;
		if ( null !== $affiliate_param ) {
			$affiliate_owner_provided = true;
			if ( '' === $affiliate_param ) {
				$affiliate_owner = 0;
			} elseif ( is_numeric( $affiliate_param ) ) {
				$affiliate_owner = (int) $affiliate_param;
			} else {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => self::msg( 'Invalid affiliate owner parameter' ),
					),
					400
				);
			}
			if ( $affiliate_owner > 0 ) {
				$owner_user = get_user_by( 'id', $affiliate_owner );
				if ( ! $owner_user instanceof WP_User || ( class_exists( 'MUMB_Affiliates' ) && ! MUMB_Affiliates::is_affiliate_user( $owner_user ) ) ) {
					return new WP_REST_Response(
						array(
							'success' => false,
							'message' => self::msg( 'Affiliate owner does not exist' ),
						),
						400
					);
				}
			}
		} else {
			// Auto-assign to current user if they are an affiliate and no owner specified
			/** @var WP_User|false $current_user */
			$current_user = wp_get_current_user();
			if ( $current_user instanceof WP_User && MUMB_Affiliates::is_affiliate_user( $current_user ) ) {
				$affiliate_owner_provided = true;
				$affiliate_owner          = $current_user->ID;
			}
		}

		$api = \MWP_REST::build_api();
		if ( ! $api ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Not configured' ),
				),
				400
			);
		}

		try {
			$payload = array();
			if ( '' !== $new_pass ) {
				$payload['password'] = $new_pass;
			}
			if ( '' !== $new_group ) {
				$payload['group'] = $new_group;
			}
			if ( '' !== $new_comment ) {
				$payload['comment'] = $new_comment;
			}
			if ( is_scalar( $shared_param ) && is_numeric( $shared_param ) ) {
				$payload['shared-users'] = (int) $shared_param;
			}
			if ( is_scalar( $disabled_param ) ) {
				$payload['disabled'] = (bool) $disabled_param;
			}

			if ( ! empty( $payload ) ) {
				$res = $api->update_user( $id, $payload );
				if ( ! \MWP_REST::is_success( $res ) ) {
					$msg = \MWP_REST::normalize_error_message( $res, 'Failed to update user' );
					throw new \RuntimeException( $msg );
				}
			} elseif ( '' === $assign_profile && ! $affiliate_owner_provided ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => self::msg( 'No changes provided' ),
					),
					400
				);
			}

			if ( '' !== $assign_profile ) {
				global $wpdb;
				if ( ! ( $wpdb instanceof \wpdb ) ) {
					return array(
						'success' => false,
						'message' => 'Database unavailable',
					);
				}
				/** @var string $p */
				$p = $wpdb->prefix;
				/** @var string|null $query */
				$query = $wpdb->prepare( 'SELECT username FROM ' . $p . 'mikrotik_users WHERE username=%s OR um_id=%s LIMIT 1', $id, $id ); // @phpstan-ignore-line
				/** @var mixed $username */
				$username    = $wpdb->get_var( $query );
				$target_user = is_string( $username ) && '' !== $username ? $username : $id;
				MUMB_Queue::enqueue(
					'assign_profile:' . $target_user,
					array(
						'type'    => 'assign_profile',
						'user'    => $target_user,
						'profile' => $assign_profile,
					)
				);
				try {
					MUMB_Queue::process();
				} catch ( \Throwable $e ) {
					// ignore
				}
			}

			$wp_user_id = 0;
			if ( class_exists( 'MUMB_WP_Users' ) ) {
				$meta = array();
				if ( '' !== $new_pass ) {
					$meta['password'] = $new_pass;
				}
				if ( '' !== $new_group ) {
					$meta['group'] = $new_group;
				}
				if ( '' !== $new_comment ) {
					$meta['comment'] = $new_comment;
				}
				if ( '' !== $assign_profile ) {
					$meta['profile'] = $assign_profile;
				}
				if ( is_scalar( $shared_param ) && is_numeric( $shared_param ) ) {
					$meta['shared-users'] = (int) $shared_param;
				}
				if ( is_scalar( $disabled_param ) ) {
					$meta['disabled'] = (bool) $disabled_param ? 1 : 0;
				}
				if ( ! empty( $meta ) ) {
					$wp_user_id = MUMB_WP_Users::ensure_user( $id, $meta );
				}
			}

			if ( $affiliate_owner_provided ) {
				if ( $wp_user_id === 0 ) {
					$wp_user    = get_user_by( 'login', $id );
					$wp_user_id = $wp_user instanceof WP_User ? $wp_user->ID : 0;
				}
				if ( $wp_user_id > 0 ) {
					if ( $affiliate_owner > 0 ) {
						update_user_meta( $wp_user_id, 'mumb_affiliate_owner', $affiliate_owner );
					} else {
						delete_user_meta( $wp_user_id, 'mumb_affiliate_owner' );
					}
				}
			}

			if ( '' !== $assign_profile ) {
				( new MUMB_Sync_Service() )->sync_user_profiles();
			} else {
				( new MUMB_Sync_Service() )->sync_users();
			}

			self::flush_cache();
			return array( 'success' => true );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'error', 'rest_users_update', $e->getMessage(), array( 'id' => $id ) );
			}
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	public static function delete_user( WP_REST_Request $req ): array|WP_REST_Response {
		/** @var mixed $id_param */
		$id_param = $req->get_param( 'id' );
		$id       = sanitize_text_field( is_scalar( $id_param ) ? (string) $id_param : '' );
		if ( '' === $id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Missing user id' ),
				),
				400
			);
		}

		$api = \MWP_REST::build_api();
		if ( ! $api ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Not configured' ),
				),
				400
			);
		}

		try {
			$res = $api->delete_user( $id );
			if ( ! \MWP_REST::is_success( $res ) ) {
				$msg = \MWP_REST::normalize_error_message( $res, 'Failed to delete user' );
				throw new \RuntimeException( $msg );
			}
			( new MUMB_Sync_Service() )->sync_users();
			self::flush_cache();
			return array( 'success' => true );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'error', 'rest_users_delete', $e->getMessage(), array( 'id' => $id ) );
			}
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	public static function flush_cache(): void {
		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return;
		}
		$pattern = '_transient_' . self::CACHE_KEY . ':';
		/** @var string|null $query */
		$query = $wpdb->prepare( 'SELECT option_name FROM ' . $wpdb->options . ' WHERE option_name LIKE %s', $pattern . '%' ); // @phpstan-ignore-line
		/** @var mixed $keys */
		$keys = $wpdb->get_col( $query );
		/** @var mixed $key */
		foreach ( (array) $keys as $key ) {
			$transient = str_replace( '_transient_', '', is_string( $key ) ? $key : '' );
			delete_transient( $transient );
		}
	}

	/**
	 * @param array{success: bool, data: array<int, array<string, mixed>>} $payload
	 * @return array{success: bool, data: array<int, array<string, mixed>>, meta: array<string, mixed>}
	 */
	private static function paginate_payload( array $payload, int $page, int $per_page ): array {
		$data   = $payload['data'];
		$total  = count( $data );
		$offset = ( $page - 1 ) * $per_page;
		$items  = array_slice( $data, $offset, $per_page );

		return array(
			'success' => true,
			'data'    => $items,
			'meta'    => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
			),
		);
	}

	private static function msg( string $text ): string {
		return \__( $text, 'mikrotik-user-manager-bridge' );
	}
}
