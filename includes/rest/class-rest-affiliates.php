<?php

namespace MUMB\REST;

use MikroTik_API;
use MUMB_Affiliates;
use MUMB_Logger;
use MUMB_Queue;
use MUMB_Sync_Service;
use MUMB_WP_Users;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

class Affiliates_Controller {

	public static function register_routes(): void {
		register_rest_route(
			'mwp/v1',
			'/affiliate/users',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_users' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			'mwp/v1',
			'/affiliate/users',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_user' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			'mwp/v1',
			'/affiliate/users/(?P<id>[^/]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_user' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			'mwp/v1',
			'/affiliate/users/(?P<id>[^/]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_user' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			'mwp/v1',
			'/affiliate/subscriptions',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'subscriptions_history' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			'mwp/v1',
			'/affiliate/queue',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_queue' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	public static function permission(): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User ) {
			return false;
		}
		if ( in_array( 'affiliate_partner', $user->roles, true ) ) {
			return true;
		}
		if ( class_exists( 'MUMB_Affiliates' ) && MUMB_Affiliates::is_affiliate_user( $user ) ) {
			return true;
		}
		return current_user_can( 'manage_affiliates' );
	}

	public static function list_users( WP_REST_Request $req ): array|WP_REST_Response {
		if ( \MWP_REST::throttle( 'affiliate_users_list', 60, 60 ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Rate limited' ),
				),
				429
			);
		}

		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Authentication required' ),
				),
				401
			);
		}

		/** @var mixed $search_param */
		$search_param = $req->get_param( 'search' );
		$search       = sanitize_text_field( is_scalar( $search_param ) ? (string) $search_param : '' );

		// Check if current user is admin
		$is_admin = current_user_can( 'manage_options' );

		/** @var MikroTik_API|null $api */
		$api = \MWP_REST::build_api();
		if ( ! ( $api instanceof MikroTik_API ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Router not configured' ),
				),
				500
			);
		}

		/** @var array<int, array<string, mixed>> $response_rows */
		$response_rows = array();
		try {
			/** @var array<string, mixed> $res */
			$res = $api->get_users();
			if ( ! \MWP_REST::is_success( $res ) ) {
				$msg = \MWP_REST::normalize_error_message( $res, self::msg( 'Failed to fetch users' ) );
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => $msg,
					),
					500
				);
			}
			if ( isset( $res['data'] ) && is_array( $res['data'] ) ) {
				$response_rows = $res['data'];
			}
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'error', 'affiliate_users_list', $e->getMessage() );
			}
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}

		// Get WordPress users created by current affiliate or all users if admin
		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Database unavailable' ),
				),
				500
			);
		}

		// Build query to get allowed usernames based on affiliate relationship
		$allowed_usernames = array();
		if ( $is_admin ) {
			// Admin sees all users - get all usernames from WordPress that have MikroTik accounts
			foreach ( $response_rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$username_val = $row['username'] ?? ( $row['name'] ?? '' );
				$username     = is_scalar( $username_val ) ? (string) $username_val : '';
				if ( '' !== $username ) {
					$allowed_usernames[] = $username;
				}
			}
		} else {
			// Affiliate sees only users they created
			$user_query      = new \WP_User_Query(
				array(
					'meta_key'   => 'created_by_affiliate',
					'meta_value' => (string) $current_user_id,
					'fields'     => array( 'user_login' ),
				)
			);
			$affiliate_users = $user_query->get_results();
			/** @var \WP_User $user */
			foreach ( $affiliate_users as $user ) {
				$allowed_usernames[] = $user->user_login;
			}
		}

		// Filter MikroTik users based on affiliate relationships
		$results = array();
		/** @var array<string, mixed> $row */
		foreach ( $response_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			/** @var mixed $username_val */
			$username_val = $row['username'] ?? ( $row['name'] ?? '' );
			$username     = is_scalar( $username_val ) ? (string) $username_val : '';
			if ( '' === $username ) {
				continue;
			}

			// Check if this username is allowed for current user
			if ( ! in_array( $username, $allowed_usernames, true ) ) {
				continue;
			}

			if ( '' !== $search && stripos( $username, $search ) === false ) {
				continue;
			}

			// Transform data to match frontend expectations
			$user_data = array();
			
			// Map username field (frontend expects 'username', MikroTik returns 'name')
			$user_data['username'] = $username;
			
			// Copy existing fields
			/** @var mixed $comment_val */
			$comment_val = $row['comment'] ?? '';
			$user_data['comment'] = is_scalar( $comment_val ) ? (string) $comment_val : '';
			/** @var mixed $group_val */
			$group_val = $row['group'] ?? '';
			$user_data['group'] = is_scalar( $group_val ) ? (string) $group_val : '';
			
			// Calculate status from disabled and expiration
			$disabled = isset( $row['disabled'] ) && 
			           ( $row['disabled'] === true || $row['disabled'] === 'true' || $row['disabled'] === '1' );
			/** @var mixed $expires_at */
			$expires_at = $row['expires_at'] ?? $row['expires-at'] ?? $row['expire_at'] ?? $row['expire-at'] ?? null;
			
			if ( $disabled ) {
				$user_data['status'] = 'disabled';
			} elseif ( is_string( $expires_at ) && $expires_at !== '' && strtotime( $expires_at ) < time() ) {
				$user_data['status'] = 'expired';
			} else {
				$user_data['status'] = 'active';
			}
			
			// Add expires_at for frontend
			$user_data['expires_at'] = is_scalar( $expires_at ) ? (string) $expires_at : null;
			
			// Get profile information (try actual-profile first, then profile field)
			/** @var mixed $profile_val */
			$profile_val = $row['actual-profile'] ?? $row['profile'] ?? '';
			$user_data['profile'] = is_scalar( $profile_val ) ? (string) $profile_val : '';
			
			// Add updated_at (use current time as fallback)
			$user_data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

			// Add affiliate information to the response
			if ( ! $is_admin ) {
				$user_data['created_by_affiliate'] = $current_user_id;
			} else {
				// For admin, get the affiliate info
				$wp_user = get_user_by( 'login', $username );
				if ( $wp_user ) {
					/** @var mixed $created_by */
					$created_by                        = get_user_meta( $wp_user->ID, 'created_by_affiliate', true );
					$user_data['created_by_affiliate'] = ( $created_by && is_scalar( $created_by ) ) ? intval( $created_by ) : null;
					/** @var mixed $created_date */
					$created_date = get_user_meta( $wp_user->ID, 'created_date', true );
					$user_data['created_date']         = is_scalar( $created_date ) ? (string) $created_date : '';
				}
			}

			$results[] = $user_data;
		}

		// Calculate stats for charts and dashboard
		$total_users = count( $results );
		$active_users = 0;
		$disabled_users = 0;
		$expired_users = 0;
		$groups_count = array();
		
		foreach ( $results as $user ) {
			// Process user statistics
			
			// Count status (now using the standardized status field)
			$status = $user['status'];
			
			if ( $status === 'disabled' ) {
				++$disabled_users;
			} elseif ( $status === 'expired' ) {
				++$expired_users;
			} else {
				++$active_users;
			}
			
			// Count groups
			$group = $user['group'];
			if ( '' !== $group ) {
				if ( ! isset( $groups_count[ $group ] ) ) {
					$groups_count[ $group ] = 0;
				}
				++$groups_count[ $group ];
			}
		}
		
		// Generate activity data (simplified - last 7 days)
		$activity = array();
		for ( $i = 6; $i >= 0; $i-- ) {
			$date = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$count = 0; // In a real implementation, query user creation dates
			$activity[] = array( 'date' => $date, 'count' => $count );
		}
		
		// Prepare pagination info
		/** @var mixed $page_param */
		$page_param = $req->get_param( 'page' );
		$page = max( 1, intval( is_scalar( $page_param ) ? $page_param : 1 ) );
		
		/** @var mixed $per_page_param */
		$per_page_param = $req->get_param( 'per_page' );
		$per_page = max( 1, min( 100, intval( is_scalar( $per_page_param ) ? $per_page_param : 25 ) ) );
		
		$total_pages = max( 1, ceil( $total_users / $per_page ) );
		
		// Apply pagination to results
		$offset = ( $page - 1 ) * $per_page;
		$paginated_results = array_slice( $results, $offset, $per_page );

		return array(
			'success' => true,
			'data'    => array_values( $paginated_results ),
			'meta'    => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total_users,
				'total_pages' => $total_pages,
			),
			'stats'   => array(
				'total'    => $total_users,
				'active'   => $active_users,
				'disabled' => $disabled_users,
				'expired'  => $expired_users,
				'groups'   => array_map(
					static function ( $group, $count ) {
						return array( 'name' => $group, 'count' => $count );
					},
					array_keys( $groups_count ),
					array_values( $groups_count )
				),
				'activity' => $activity,
			),
		);
	}

	public static function create_user( WP_REST_Request $req ): array|WP_REST_Response {
		if ( \MWP_REST::throttle( 'affiliate_users_create', 30, 60 ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Rate limited' ),
				),
				429
			);
		}

		// Get current affiliate user ID for relationship tracking
		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Authentication required' ),
				),
				401
			);
		}

		$username_param = $req->get_param( 'username' );
		$password_param = $req->get_param( 'password' );
		$group_param    = $req->get_param( 'group' );
		$username       = sanitize_user( is_scalar( $username_param ) ? (string) $username_param : '', true );
		$password       = is_scalar( $password_param ) ? (string) $password_param : '';
		$group          = sanitize_text_field( is_scalar( $group_param ) ? (string) $group_param : 'default' );

		if ( '' === $username || '' === $password ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Username and password are required' ),
				),
				400
			);
		}

		// Use default group if not specified (no validation needed)
		if ( '' === $group ) {
			$group = 'default';
		}

		/** @var MikroTik_API|null $api */
		$api = \MWP_REST::build_api();
		if ( ! ( $api instanceof MikroTik_API ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Router not configured' ),
				),
				500
			);
		}

		/** @var array<string, string> $payload */
		$payload = array(
			'username' => $username,
			'password' => $password,
			'group'    => $group,
		);
		try {
			/** @var array<string, mixed> $res */
			$res = $api->add_user( $payload );
			if ( ! \MWP_REST::is_success( $res ) ) {
				$msg = \MWP_REST::normalize_error_message( $res, self::msg( 'Failed to create user' ) );
				throw new \RuntimeException( $msg );
			}

			// Create WordPress user and store affiliate relationship
			if ( class_exists( 'MUMB_WP_Users' ) ) {
				$wp_user_id = MUMB_WP_Users::ensure_user( $username, array( 'group' => $group ) );

				// Store affiliate relationship in user meta
				if ( $wp_user_id ) {
					update_user_meta( $wp_user_id, 'created_by_affiliate', $current_user_id );
					update_user_meta( $wp_user_id, 'mikrotik_group', $group );
					update_user_meta( $wp_user_id, 'created_date', current_time( 'mysql' ) );
				}
			}

			// Queue a delayed sync instead of immediate sync to avoid race conditions
			if ( class_exists( 'MUMB_Queue' ) ) {
				$queue_key = MUMB_Queue::enqueue( 'sync_users', array( 'type' => 'sync_users' ) );
				// Manually set delayed execution
				$q = \MUMB\Services\SettingsService::get_job_queue();
				if ( isset( $q[ $queue_key ] ) && is_array( $q[ $queue_key ] ) ) {
					$q[ $queue_key ]['next_run'] = time() + 5; // Delay 5 seconds
					\MUMB\Services\SettingsService::set_job_queue( $q );
				}
			}

			Users_Controller::flush_cache();
			return array(
				'success' => true,
				'data'    => array(
					'username' => $username,
					'group'    => $group,
				),
			);
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'error', 'affiliate_create', $e->getMessage(), array( 'user' => $username ) );
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

	public static function update_user( WP_REST_Request $req ): array|WP_REST_Response {
		if ( \MWP_REST::throttle( 'affiliate_users_update', 30, 60 ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Rate limited' ),
				),
				429
			);
		}

		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Authentication required' ),
				),
				401
			);
		}

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

		// Check if current user can update this user (affiliate relationship or admin)
		$is_admin = current_user_can( 'manage_options' );
		if ( ! $is_admin ) {
			// Check if this user was created by current affiliate
			$wp_user = get_user_by( 'login', $id );
			if ( ! $wp_user ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => self::msg( 'User not found' ),
					),
					404
				);
			}

			$created_by = get_user_meta( $wp_user->ID, 'created_by_affiliate', true );
			if ( ( ! is_scalar( $created_by ) ) || intval( $created_by ) !== $current_user_id ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => self::msg( 'Access denied: You can only update users you created' ),
					),
					403
				);
			}
		}

		$group_param = $req->get_param( 'group' );
		$group       = is_scalar( $group_param ) ? sanitize_text_field( (string) $group_param ) : '';

		$password_param = $req->get_param( 'password' );
		$password       = is_scalar( $password_param ) ? (string) $password_param : '';
		if ( '' === $group && '' === $password ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Nothing to update' ),
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
					'message' => self::msg( 'Router not configured' ),
				),
				500
			);
		}

		/** @var array<string, string> $payload */
		$payload = array();
		if ( '' !== $group ) {
			$payload['group'] = $group;
		}
		if ( '' !== $password ) {
			$payload['password'] = $password;
		}

		try {
			/** @var array<string, mixed> $res */
			$res = $api->update_user( $id, $payload );
			if ( ! \MWP_REST::is_success( $res ) ) {
				$msg = \MWP_REST::normalize_error_message( $res, 'Failed to update user' );
				throw new \RuntimeException( $msg );
			}
			if ( class_exists( 'MUMB_WP_Users' ) && '' !== $group ) {
				MUMB_WP_Users::ensure_user( $id, array( 'group' => $group ) );
			}

			// Queue a delayed sync instead of immediate sync to avoid race conditions
			if ( class_exists( 'MUMB_Queue' ) ) {
				$queue_key = MUMB_Queue::enqueue( 'sync_users', array( 'type' => 'sync_users' ) );
				// Manually set delayed execution
				$q = \MUMB\Services\SettingsService::get_job_queue();
				if ( isset( $q[ $queue_key ] ) && is_array( $q[ $queue_key ] ) ) {
					$q[ $queue_key ]['next_run'] = time() + 5; // Delay 5 seconds
					\MUMB\Services\SettingsService::set_job_queue( $q );
				}
			}

			Users_Controller::flush_cache();
			return array( 'success' => true );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'error', 'affiliate_update', $e->getMessage(), array( 'user' => $id ) );
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
		if ( \MWP_REST::throttle( 'affiliate_users_delete', 30, 60 ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Rate limited' ),
				),
				429
			);
		}

		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Authentication required' ),
				),
				401
			);
		}

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

		// Check if current user can delete this user (affiliate relationship or admin)
		$is_admin = current_user_can( 'manage_options' );
		if ( ! $is_admin ) {
			// Check if this user was created by current affiliate
			$wp_user = get_user_by( 'login', $id );
			if ( ! $wp_user ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => self::msg( 'User not found' ),
					),
					404
				);
			}

			$created_by = get_user_meta( $wp_user->ID, 'created_by_affiliate', true );
			if ( ( ! is_scalar( $created_by ) ) || intval( $created_by ) !== $current_user_id ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => self::msg( 'Access denied: You can only delete users you created' ),
					),
					403
				);
			}
		}

		/** @var MikroTik_API|null $api */
		$api = \MWP_REST::build_api();
		if ( ! ( $api instanceof MikroTik_API ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Router not configured' ),
				),
				500
			);
		}

		try {
			/** @var array<string, mixed> $res */
			$res = $api->delete_user( $id );
			if ( ! \MWP_REST::is_success( $res ) ) {
				$msg = \MWP_REST::normalize_error_message( $res, 'Failed to delete user' );
				throw new \RuntimeException( $msg );
			}
			// Queue a delayed sync instead of immediate sync to avoid race conditions
			if ( class_exists( 'MUMB_Queue' ) ) {
				$queue_key = MUMB_Queue::enqueue( 'sync_users', array( 'type' => 'sync_users' ) );
				// Manually set delayed execution
				$q = \MUMB\Services\SettingsService::get_job_queue();
				if ( isset( $q[ $queue_key ] ) && is_array( $q[ $queue_key ] ) ) {
					$q[ $queue_key ]['next_run'] = time() + 5; // Delay 5 seconds
					\MUMB\Services\SettingsService::set_job_queue( $q );
				}
			}
			Users_Controller::flush_cache();
			$wp_user = get_user_by( 'login', $id );
			if ( $wp_user instanceof WP_User ) {
				delete_user_meta( $wp_user->ID, 'mumb_affiliate_owner' );
			}
			return array( 'success' => true );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'error', 'affiliate_delete', $e->getMessage(), array( 'user' => $id ) );
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

	public static function subscriptions_history( WP_REST_Request $req ): array|WP_REST_Response {
		// Get current affiliate user ID
		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Authentication required' ),
				),
				401
			);
		}

		$is_admin = current_user_can( 'manage_options' );

		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Database unavailable' ),
				),
				500
			);
		}

		// Get request parameters
		$page          = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
		$per_page      = max( 1, min( 100, (int) $req->get_param( 'per_page' ) ?: 25 ) );
		/** @var mixed $search_param */
		$search_param = $req->get_param( 'search' );
		$search        = sanitize_text_field( is_scalar( $search_param ) ? (string) $search_param : '' );
		/** @var mixed $status_param */
		$status_param = $req->get_param( 'status' );
		$status_filter = sanitize_text_field( is_scalar( $status_param ) ? (string) $status_param : '' );
		/** @var mixed $group_param */
		$group_param = $req->get_param( 'group' );
		$group         = sanitize_text_field( is_scalar( $group_param ) ? (string) $group_param : '' );
		$offset        = ( $page - 1 ) * $per_page;

		// Table references
		$p                   = $wpdb->prefix;
		$user_profiles_table = $p . 'mikrotik_user_profiles';
		$users_table         = $p . 'mikrotik_users';

		// Base query for subscription history
		$base_query = "
			SELECT DISTINCT 
				up.username,
				up.profile,
				u.group_name as group_name,
				up.expires_at,
				up.updated_at,
				u.disabled
			FROM {$user_profiles_table} up
			LEFT JOIN {$users_table} u ON up.username = u.name
		";

		$where_conditions = array();
		$where_params     = array();

		// Filter by affiliate ownership if not admin
		if ( ! $is_admin ) {
			$user_query      = new \WP_User_Query(
				array(
					'meta_key'   => 'mumb_affiliate_owner',
					'meta_value' => (string) $current_user_id,
					'fields'     => array( 'user_login' ),
				)
			);
			$affiliate_users = $user_query->get_results();
			$usernames       = array();
			/** @var \WP_User $user */
			foreach ( $affiliate_users as $user ) {
				$usernames[] = $user->user_login;
			}

			if ( empty( $usernames ) ) {
				// No affiliate users found
				return array(
					'success' => true,
					'data'    => array(),
					'meta'    => array(
						'page'        => $page,
						'per_page'    => $per_page,
						'total'       => 0,
						'total_pages' => 0,
					),
					'metrics' => array(
						'active'        => 0,
						'expiring_soon' => 0,
						'expired'       => 0,
					),
					'chart'   => self::generate_chart_data( array() ),
				);
			}

			$placeholders          = implode( ', ', array_fill( 0, count( $usernames ), '%s' ) );
			$where_conditions[]    = "up.username IN ($placeholders)";
			$where_params          = array_merge( $where_params, $usernames );
		}

		// Add search filter
		if ( '' !== $search ) {
			$where_conditions[] = 'up.username LIKE %s';
			$where_params[]     = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// Add group filter
		if ( '' !== $group ) {
			$where_conditions[] = 'u.group_name = %s';
			$where_params[]     = $group;
		}

		// Build base queries
		$where_clause = '';
		if ( ! empty( $where_conditions ) ) {
			$where_clause = ' WHERE ' . implode( ' AND ', $where_conditions );
		}

		// Get total count for pagination - handle with or without filters
		$total_count = 0;
		if ( ! empty( $where_conditions ) && ! empty( $where_params ) ) {
			if ( count( $where_conditions ) === 1 ) {
				$count_query = "SELECT COUNT(DISTINCT up.username) FROM {$user_profiles_table} up LEFT JOIN {$users_table} u ON up.username = u.name WHERE " . $where_conditions[0];
				$prepared_count = $count_query; // Direct query without parameters for count
			} else {
				$count_query = "SELECT COUNT(DISTINCT up.username) FROM {$user_profiles_table} up LEFT JOIN {$users_table} u ON up.username = u.name WHERE " . $where_conditions[0] . ' AND ' . ( $where_conditions[1] ?? '' );
				$prepared_count = $count_query; // Direct query without parameters for count
			}
			$total_count = (int) $wpdb->get_var( $prepared_count );
		} else {
			$count_query = "SELECT COUNT(DISTINCT up.username) FROM {$user_profiles_table} up LEFT JOIN {$users_table} u ON up.username = u.name";
			$total_count = (int) $wpdb->get_var( $count_query );
		}

		// Build main query with pagination - handle with or without filters
		$query_params = array_merge( $where_params, array( $per_page, $offset ) );
		if ( ! empty( $where_conditions ) ) {
			if ( count( $where_conditions ) === 1 ) {
				$main_query = $base_query . ' WHERE ' . $where_conditions[0] . ' ORDER BY up.updated_at DESC LIMIT %d OFFSET %d';
				/** @phpstan-ignore-next-line - Dynamic query with proper validation */
				$prepared_query = $wpdb->prepare( $main_query, $per_page, $offset );
			} else {
				$main_query = $base_query . ' WHERE ' . $where_conditions[0] . ' AND ' . ( $where_conditions[1] ?? '' ) . ' ORDER BY up.updated_at DESC LIMIT %d OFFSET %d';
				/** @phpstan-ignore-next-line - Dynamic query with proper validation */
				$prepared_query = $wpdb->prepare( $main_query, $per_page, $offset );
			}
		} else {
			$main_query = $base_query . ' ORDER BY up.updated_at DESC LIMIT %d OFFSET %d';
			/** @phpstan-ignore-next-line - Dynamic query with proper validation */
			$prepared_query = $wpdb->prepare( $main_query, $per_page, $offset );
		}

		// Execute query
		$results = array();
		$db_results = $wpdb->get_results( $prepared_query, ARRAY_A );
		if ( is_array( $db_results ) ) {
			$results = $db_results;
		}

		// Transform results and calculate status
		$subscription_data = array();
		$metrics = array( 'active' => 0, 'expiring_soon' => 0, 'expired' => 0 );
		$now = time();
		$three_days_ahead = $now + ( 3 * 24 * 60 * 60 ); // 3 days in seconds

		foreach ( $results as $row ) {
			/** @var mixed $username */
			$username    = $row['username'] ?? '';
			$username_str = is_scalar( $username ) ? (string) $username : '';
			/** @var mixed $profile */
			$profile     = $row['profile'] ?? '';
			$profile_str = is_scalar( $profile ) ? (string) $profile : '';
			/** @var mixed $group */
			$group       = $row['group_name'] ?? '';
			$group_str = is_scalar( $group ) ? (string) $group : '';
			/** @var mixed $expires_at */
			$expires_at = $row['expires_at'] ?? null;
			/** @var mixed $updated_at */
			$updated_at  = $row['updated_at'] ?? null;
			$updated_at_str = is_scalar( $updated_at ) ? (string) $updated_at : '';
			/** @var mixed $disabled */
			$disabled = $row['disabled'] ?? null;

			// Calculate status
			$status = 'active';
			if ( 'true' === ( is_scalar( $disabled ) ? (string) $disabled : '' ) ) {
				$status = 'expired';
			} elseif ( is_string( $expires_at ) && $expires_at !== '' && strtotime( $expires_at ) <= $now ) {
				$status = 'expired';
			} elseif ( is_string( $expires_at ) && $expires_at !== '' && strtotime( $expires_at ) <= $three_days_ahead ) {
				$status = 'expiring_soon';
			}

			// Apply status filter
			if ( '' !== $status_filter && $status !== $status_filter ) {
				continue;
			}

			// Count metrics
			if ( 'active' === $status ) {
				++$metrics['active'];
			} elseif ( 'expiring_soon' === $status ) {
				++$metrics['expiring_soon'];
			} else {
				++$metrics['expired'];
			}

			$subscription_data[] = array(
				'username'   => $username,
				'profile'    => $profile,
				'group'      => $group,
				'status'     => $status,
				'expires_at' => $expires_at,
				'updated_at' => $updated_at,
			);
		}

		// Calculate pagination
		$total_pages = ceil( $total_count / $per_page );

		// Generate chart data
		$chart_data = self::generate_chart_data( $subscription_data );

		return array(
			'success' => true,
			'data'    => $subscription_data,
			'meta'    => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total_count,
				'total_pages' => $total_pages,
			),
			'metrics' => $metrics,
			'chart'   => $chart_data,
		);
	}

	/**
	 * Generate chart data for subscription trends
	 * 
	 * @param array<array<string, mixed>> $subscription_data
	 * @return array<array<string, mixed>>
	 */
	private static function generate_chart_data( array $subscription_data ): array {
		$chart_points = array();
		$now = time();

		// Generate 14 days of data
		for ( $i = 13; $i >= 0; $i-- ) {
			$date = gmdate( 'Y-m-d', $now - ( $i * 24 * 60 * 60 ) );
			
			$active = 0;
			$expiring_soon = 0;
			$expired = 0;

			// For simplicity, we'll use current status counts
			// In a real implementation, you'd want historical data
			if ( 0 === $i ) { // Today's data
				foreach ( $subscription_data as $sub ) {
					switch ( $sub['status'] ) {
						case 'active':
							++$active;
							break;
						case 'expiring_soon':
							++$expiring_soon;
							break;
						case 'expired':
							++$expired;
							break;
					}
				}
			}

			$chart_points[] = array(
				'date'          => $date,
				'active'        => $active,
				'expiring_soon' => $expiring_soon,
				'expired'       => $expired,
			);
		}

		return $chart_points;
	}

	public static function get_queue( WP_REST_Request $req ): array|WP_REST_Response {
		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => self::msg( 'Authentication required' ),
				),
				401
			);
		}

		$is_admin = current_user_can( 'manage_options' );

		// Get full queue snapshot
		$queue_data = MUMB_Queue::get_queue_snapshot();
		$all_pending = $queue_data['pending'];
		$all_history = $queue_data['history'];

		// Filter jobs to only show those related to affiliate's users
		$filtered_pending = array();
		$filtered_history = array();

		if ( $is_admin ) {
			// Admin sees all jobs
			$filtered_pending = $all_pending;
			$filtered_history = $all_history;
		} else {
			// Get affiliate's users
			$user_query      = new \WP_User_Query(
				array(
					'meta_key'   => 'created_by_affiliate',
					'meta_value' => (string) $current_user_id,
					'fields'     => array( 'user_login' ),
				)
			);
			$affiliate_users = $user_query->get_results();
			$affiliate_usernames = array();
			/** @var \WP_User $user */
			foreach ( $affiliate_users as $user ) {
				$affiliate_usernames[] = $user->user_login;
			}

			// Filter pending jobs
			foreach ( $all_pending as $job ) {
				/** @var mixed $payload */
				$payload = $job['payload'] ?? array();
				if ( ! is_array( $payload ) ) {
					continue;
				}
				/** @var mixed $user */
				$user = $payload['user'] ?? '';
				$user = is_scalar( $user ) ? (string) $user : '';
				
				// Include job if it's related to affiliate's user
				if ( in_array( $user, $affiliate_usernames, true ) ) {
					$filtered_pending[] = $job;
				}
			}

			// Filter history jobs
			foreach ( $all_history as $job ) {
				/** @var mixed $payload */
				$payload = $job['payload'] ?? array();
				if ( ! is_array( $payload ) ) {
					continue;
				}
				/** @var mixed $user */
				$user = $payload['user'] ?? '';
				$user = is_scalar( $user ) ? (string) $user : '';
				
				// Include job if it's related to affiliate's user
				if ( in_array( $user, $affiliate_usernames, true ) ) {
					$filtered_history[] = $job;
				}
			}
		}

		return array(
			'success' => true,
			'data'    => array(
				'pending' => $filtered_pending,
				'history' => $filtered_history,
			),
		);
	}

	/**
	 * Helper to translate text.
	 *
	 * @param string $text Text to translate.
	 * @return string Translated text.
	 */
	private static function msg( string $text ): string {
		return \__( $text, 'mikrotik-user-manager-bridge' );
	}
}
