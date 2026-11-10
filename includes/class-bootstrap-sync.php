<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MUMB\Services\CapabilityService;
use MUMB\Services\SettingsService;

class MUMB_Bootstrap_Sync {

	protected MUMB_Settings $settings;
	protected ?MikroTik_API $api      = null;
	protected bool $has_woocommerce   = false;
	protected bool $has_subscriptions = false;
	/** @var array<string, int> */
	protected array $profile_product_map = array();

	public function __construct( ?MikroTik_API $api = null ) {
		$this->settings          = MUMB_Settings::load();
		$this->api               = $api ?: $this->build_api();
		$this->has_woocommerce   = class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
		$this->has_subscriptions = $this->has_woocommerce && function_exists( 'wcs_create_subscription' );
		/** @var array<string, mixed> $map_raw */
		$map_raw = SettingsService::get_profile_map();
		/** @var array<string, mixed> $map */
		$map = $map_raw;
		foreach ( $map as $profile => $product_id ) {
			/** @var string $profile */
			/** @var mixed $product_id */
			if ( is_scalar( $product_id ) ) {
				$this->profile_product_map[ $profile ] = (int) $product_id;
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function dry_run(): array {
		return $this->run( false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function commit(): array {
		return $this->run( true );
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function run( bool $commit ): array {
		if ( ! $this->api ) {
			return array(
				'success' => false,
				'message' => __( 'Router connection is not configured.', 'mikrotik-user-manager-bridge' ),
			);
		}

		try {
			( new CapabilityService() )->probe();
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'warning', 'capabilities', 'Capability probe during bootstrap failed', array( 'error' => $e->getMessage() ) );
			}
		}

			/** @var array{success:bool,data?:array<string,mixed>,message?:string} $remote */
			$remote = $this->fetch_remote_state();
		if ( $remote['success'] !== true ) {
			return $remote;
		}

		if ( ! isset( $remote['data'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid remote data structure.', 'mikrotik-user-manager-bridge' ),
			);
		}

			/** @var array<string,mixed> $remote_data */
			$remote_data = $remote['data'];
			$analysis    = $this->analyse_remote_state( $remote_data );
		if ( $commit ) {
			$result = $this->apply_bootstrap( $analysis, $remote['data'] );
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'notice', 'bootstrap_sync', 'Bootstrap sync executed', array( 'summary' => $result['summary'] ?? array() ) );
			}
			update_option(
				'mumb_bootstrap_state',
				array(
					'completed_at' => current_time( 'mysql' ),
					'summary'      => $result['summary'] ?? array(),
				),
				false
			);
			return $result;
		}

		if ( class_exists( 'MUMB_Logger' ) ) {
			MUMB_Logger::log( 'info', 'bootstrap_sync_preview', 'Bootstrap sync dry-run', array( 'summary' => $analysis['summary'] ) );
		}

		return array(
			'success'  => true,
			'mode'     => 'dry-run',
			'summary'  => $analysis['summary'],
			'users'    => $analysis['users'],
			'groups'   => $analysis['groups'],
			'profiles' => $analysis['profiles'],
			'notes'    => $analysis['notes'],
		);
	}

	/**
	 * @return array{success:bool, data?:array<string, mixed>, message?:string}
	 */
	protected function fetch_remote_state(): array {
		$collections = array(
			'users'       => array(),
			'groups'      => array(),
			'profiles'    => array(),
			'assignments' => array(),
		);
		foreach (
			array(
				'users'       => 'get_users',
				'groups'      => 'get_groups',
				'profiles'    => 'get_profiles',
				'assignments' => 'get_user_profiles',
			) as $key => $method
		) {
			try {
				/** @var mixed $response_raw */
				$response_raw = $this->api->{$method}();
			} catch ( \Throwable $e ) {
				return array(
					'success' => false,
					'message' => sprintf( __( 'Failed to query MikroTik %1$s: %2$s', 'mikrotik-user-manager-bridge' ), $key, $e->getMessage() ),
				);
			}
			if ( ! is_array( $response_raw ) || ( $response_raw['status'] ?? '' ) !== 'ok' ) {
				/** @var array<string, mixed> $response */
				$response = is_array( $response_raw ) ? $response_raw : array();
				/** @var mixed $error_msg */
				$error_msg = isset( $response['error_message'] ) ? $response['error_message'] : '';
				$message   = is_string( $error_msg ) && '' !== $error_msg ? $error_msg : __( 'Unknown error', 'mikrotik-user-manager-bridge' );
				return array(
					'success' => false,
					'message' => sprintf( __( 'Failed to query MikroTik %1$s: %2$s', 'mikrotik-user-manager-bridge' ), $key, $message ),
				);
			}
			/** @var array<string, mixed> $response */
			$response = $response_raw;
			/** @var mixed $data_raw */
			$data_raw            = $response['data'] ?? array();
			$data                = is_array( $data_raw ) ? $data_raw : array();
			$collections[ $key ] = array_values( array_filter( $data, 'is_array' ) );
		}
		return array(
			'success' => true,
			'data'    => $collections,
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{summary: array<string, mixed>, users: array<int, array<string, mixed>>, groups: array<int, array<string, mixed>>, profiles: array<int, array<string, mixed>>, notes: array<int, string>}
	 */
	protected function analyse_remote_state( array $data ): array {
		$now_ts = time();
		/** @var mixed $groups_raw */
		$groups_raw = $data['groups'] ?? array();
		/** @var mixed $profiles_raw */
		$profiles_raw = $data['profiles'] ?? array();
		/** @var array<int, mixed> $groups_raw_array */
		$groups_raw_array = is_array( $groups_raw ) ? $groups_raw : array();
		/** @var array<int, array<string, mixed>> $groups_filtered */
		$groups_filtered = array_filter( $groups_raw_array, 'is_array' );
		$groups          = $groups_filtered;

		/** @var array<int, array<string, mixed>> $profiles_filtered */
		$profiles_filtered = is_array( $profiles_raw ) ? array_filter( $profiles_raw, 'is_array' ) : array();
		$profiles          = $profiles_filtered;
		/** @var mixed $assignments_raw */
		$assignments_raw = $data['assignments'] ?? array();
		/** @var array<int, array<string, mixed>> $assignments_typed */
		$assignments_typed = is_array( $assignments_raw ) ? array_filter( $assignments_raw, 'is_array' ) : array();
		$assignments       = $this->index_assignments( $assignments_typed );
		$summary           = array(
			'total_users'          => 0,
			'wp_users_existing'    => 0,
			'wp_users_missing'     => 0,
			'wp_users_conflicts'   => 0,
			'subscriptions_needed' => 0,
			'active_users'         => 0,
			'expired_users'        => 0,
		);
		$notes             = array();
		$users_analysis    = array();
			/** @var mixed $users_data */
			$users_data = $data['users'] ?? array();
		if ( is_array( $users_data ) ) {
			foreach ( $users_data as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$username = '';
				if ( isset( $row['username'] ) && is_scalar( $row['username'] ) ) {
					$username = (string) $row['username'];
				} elseif ( isset( $row['login'] ) && is_scalar( $row['login'] ) ) {
					$username = (string) $row['login'];
				} elseif ( isset( $row['name'] ) && is_scalar( $row['name'] ) ) {
					$username = (string) $row['name'];
				}

				if ( '' === $username ) {
					$notes[] = __( 'Encountered MikroTik user without a username; skipping.', 'mikrotik-user-manager-bridge' );
					continue;
				}
				++$summary['total_users'];

				$um_id = '';
				if ( isset( $row['.id'] ) && is_scalar( $row['.id'] ) ) {
					$um_id = (string) $row['.id'];
				} elseif ( isset( $row['um_id'] ) && is_scalar( $row['um_id'] ) ) {
					$um_id = (string) $row['um_id'];
				}

				$rest_id = '';
				if ( isset( $row['id'] ) && is_scalar( $row['id'] ) ) {
					$rest_id = (string) $row['id'];
				} elseif ( isset( $row['rest_id'] ) && is_scalar( $row['rest_id'] ) ) {
					$rest_id = (string) $row['rest_id'];
				}

				$profile = '';
				if ( isset( $row['group'] ) && is_scalar( $row['group'] ) ) {
					$profile = (string) $row['group'];
				} elseif ( isset( $row['profile'] ) && is_scalar( $row['profile'] ) ) {
					$profile = (string) $row['profile'];
				}

				/** @var array<string,mixed> $assignment */
				$assignment = $assignments[ strtolower( $username ) ] ?? array();
				if ( isset( $assignment['profile'] ) && is_scalar( $assignment['profile'] ) && $assignment['profile'] !== '' ) {
					$profile = (string) $assignment['profile'];
				}
				/** @var mixed $expiry_mysql */
				$expiry_mysql = $assignment['expires_at'] ?? null;
				$expiry_ts    = false;
				if ( is_string( $expiry_mysql ) && '' !== $expiry_mysql ) {
					$expiry_ts = strtotime( $expiry_mysql );
				}
				$status = 'unknown';
				if ( $expiry_ts !== false ) {
					if ( $expiry_ts <= $now_ts ) {
						$status = 'expired';
						++$summary['expired_users'];
					} else {
						$status = 'active';
						++$summary['active_users'];
					}
				}

				$wp_user   = get_user_by( 'login', $username );
				$wp_exists = $wp_user instanceof \WP_User;
				if ( $wp_exists ) {
					++$summary['wp_users_existing'];
				} else {
					++$summary['wp_users_missing'];
				}

				$conflicts = array();
				if ( $wp_exists && ! empty( $assignment['expires_at'] ) ) {
					++$summary['subscriptions_needed'];
				}
				if ( $wp_exists && $wp_user instanceof \WP_User && $wp_user->user_login !== $username ) {
					$conflicts[] = __( 'WordPress login does not match MikroTik username.', 'mikrotik-user-manager-bridge' );
				}
				if ( $wp_exists && $wp_user instanceof \WP_User && empty( $wp_user->user_email ) ) {
					$conflicts[] = __( 'WordPress user is missing an email address.', 'mikrotik-user-manager-bridge' );
				}
				if ( ! empty( $conflicts ) ) {
					$summary['wp_users_conflicts'] += count( $conflicts );
				}

				$users_analysis[] = array(
					'username'   => $username,
					'um_id'      => '' !== $um_id ? $um_id : null,
					'rest_id'    => '' !== $rest_id ? $rest_id : null,
					'profile'    => '' !== $profile ? $profile : null,
					'comment'    => isset( $row['comment'] ) ? (string) $row['comment'] : '',
					'assignment' => $assignment,
					'status'     => $status,
					'expires_at' => $assignment['expires_at'] ?? null,
					'wp_user_id' => ( $wp_exists && $wp_user instanceof \WP_User ) ? $wp_user->ID : null,
					'wp_exists'  => $wp_exists,
					'conflicts'  => $conflicts,
					'actions'    => array(
						'create_wp_user'           => ! $wp_exists,
						'create_subscription'      => ! empty( $assignment['expires_at'] ) || $status === 'active',
						'update_placeholder_email' => ( $wp_exists && $wp_user instanceof \WP_User && empty( $wp_user->user_email ) ),
					),
				);
			}
		}

		return array(
			'summary'  => $summary,
			'users'    => $users_analysis,
			'groups'   => $this->prepare_group_analysis( $groups ),
			'profiles' => $this->prepare_profile_analysis( $profiles ),
			'notes'    => $notes,
		);
	}

	/**
	 * @param array<int, mixed> $groups
	 * @return array<int, array<string, mixed>>
	 */
	protected function prepare_group_analysis( array $groups ): array {
		$out = array();
		foreach ( $groups as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'name'       => isset( $row['name'] ) && is_scalar( $row['name'] ) ? (string) $row['name'] : '',
				'um_id'      => isset( $row['.id'] ) && is_scalar( $row['.id'] ) ? (string) $row['.id'] : ( isset( $row['id'] ) && is_scalar( $row['id'] ) ? (string) $row['id'] : null ),
				'rest_id'    => isset( $row['id'] ) && is_scalar( $row['id'] ) ? (string) $row['id'] : null,
				'attributes' => isset( $row['attributes'] ) && is_scalar( $row['attributes'] ) ? (string) $row['attributes'] : null,
				'source_row' => $row,
			);
		}
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $profiles
	 * @return array<int, array<string, mixed>>
	 */
	/**
	 * @param array<int, mixed> $profiles
	 * @return array<int, array<string, mixed>>
	 */
	protected function prepare_profile_analysis( array $profiles ): array {
		$out = array();
		foreach ( $profiles as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'name'       => isset( $row['name'] ) && is_scalar( $row['name'] ) ? (string) $row['name'] : '',
				'validity'   => isset( $row['validity'] ) && is_scalar( $row['validity'] ) ? (string) $row['validity'] : ( isset( $row['valid-for'] ) && is_scalar( $row['valid-for'] ) ? (string) $row['valid-for'] : '' ),
				'um_id'      => isset( $row['.id'] ) && is_scalar( $row['.id'] ) ? (string) $row['.id'] : ( isset( $row['id'] ) && is_scalar( $row['id'] ) ? (string) $row['id'] : null ),
				'rest_id'    => isset( $row['id'] ) && is_scalar( $row['id'] ) ? (string) $row['id'] : null,
				'source_row' => $row,
			);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $analysis
	 * @param array<string, mixed> $remote
	 * @return array<string, mixed>
	 */
	protected function apply_bootstrap( array $analysis, array $remote ): array {
		global $wpdb;
		$created              = array();
		$updated              = array();
		$subscription_results = array();
		$errors               = array();
		$now                  = current_time( 'mysql' );

		$mirror_users = array();
		if ( isset( $analysis['users'] ) && is_array( $analysis['users'] ) ) {
			foreach ( $analysis['users'] as $user_info ) {
				if ( ! is_array( $user_info ) ) {
					continue;
				}
				$username = isset( $user_info['username'] ) && is_scalar( $user_info['username'] ) ? (string) $user_info['username'] : '';
				/** @var mixed $wp_id */
				$wp_id = $user_info['wp_user_id'] ?? null;
				if ( '' === $username ) {
					continue;
				}
				/** @var mixed $wp_exists */
				$wp_exists = isset( $user_info['wp_exists'] ) ? $user_info['wp_exists'] : false;
				if ( ! $wp_exists ) {
					$create = $this->ensure_wp_user( $username, $user_info );
					if ( isset( $create['error'] ) ) {
						$errors[] = $create['error'];
						continue;
					}
					if ( isset( $create['user_id'] ) ) {
						$wp_id     = $create['user_id'];
						$created[] = array(
							'username' => $username,
							'user_id'  => $wp_id,
						);
					}
				} else {
					if ( isset( $user_info['actions']['update_placeholder_email'] ) && $user_info['actions']['update_placeholder_email'] && $wp_id ) {
						$wp_user = false;
						if ( is_int( $wp_id ) || is_string( $wp_id ) ) {
							$wp_user = get_user_by( 'id', $wp_id );
						}
						$this->ensure_placeholder_email( $wp_user instanceof WP_User ? $wp_user : null );
					}
					$updated[] = array(
						'username' => $username,
						'user_id'  => $wp_id,
					);
				}

				if ( $wp_id && isset( $user_info['actions']['create_subscription'] ) && $user_info['actions']['create_subscription'] ) {
					$wp_id_int = is_numeric( $wp_id ) ? (int) $wp_id : 0;
					if ( $wp_id_int > 0 ) {
						$subscription_results[ $username ] = $this->maybe_create_subscription( $wp_id_int, $user_info );
					}
				}

				$mirror_users[] = array(
					'username'   => $username,
					'um_id'      => $user_info['um_id'],
					'rest_id'    => $user_info['rest_id'],
					'profile'    => $user_info['profile'],
					'comment'    => $user_info['comment'],
					'updated_at' => $now,
				);
			}
		}

		$groups_data      = isset( $analysis['groups'] ) && is_array( $analysis['groups'] ) ? $analysis['groups'] : array();
		$profiles_data    = isset( $analysis['profiles'] ) && is_array( $analysis['profiles'] ) ? $analysis['profiles'] : array();
		$assignments_data = isset( $remote['assignments'] ) && is_array( $remote['assignments'] ) ? $remote['assignments'] : array();

		$this->persist_mirror_tables( $mirror_users, $groups_data, $profiles_data, $assignments_data );

		update_option( 'mumb_master_mode', 1, false );

		return array(
			'success'              => true,
			'mode'                 => 'commit',
			'summary'              => $analysis['summary'],
			'created_wp_users'     => $created,
			'existing_wp_users'    => $updated,
			'subscription_results' => $subscription_results,
			'errors'               => $errors,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $mirror_users
	 * @param array<int, array<string, mixed>> $groups
	 * @param array<int, array<string, mixed>> $profiles
	 * @param array<int, array<string, mixed>> $assignments
	 */
	protected function persist_mirror_tables( array $mirror_users, array $groups, array $profiles, array $assignments ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql' );
		foreach ( $mirror_users as $row ) {
			if ( empty( $row['username'] ) ) {
				continue;
			}
			$wpdb->replace(
				$p . 'mikrotik_users',
				array(
					'username'   => $row['username'],
					'um_id'      => $row['um_id'],
					'rest_id'    => $row['rest_id'],
					'profile'    => $row['profile'],
					'comment'    => $row['comment'],
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		foreach ( $groups as $group ) {
			/** @var mixed $name */
			$name = $group['name'] ?? '';
			if ( '' === $name ) {
				continue;
			}
			/** @var mixed $attributes */
			$attributes = $group['attributes'] ?? null;
			$wpdb->replace(
				$p . 'mikrotik_groups',
				array(
					'name'       => is_scalar( $name ) ? (string) $name : '',
					'um_id'      => $group['um_id'] ?? null,
					'rest_id'    => $group['rest_id'] ?? null,
					'attributes' => is_scalar( $attributes ) ? (string) $attributes : null,
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
		}

		foreach ( $profiles as $profile ) {
			/** @var mixed $name */
			$name = $profile['name'] ?? '';
			if ( '' === $name ) {
				continue;
			}
			/** @var mixed $raw_source */
			$raw_source = $profile['source_row'] ?? null;
			$raw_json   = null;
			if ( is_array( $raw_source ) ) {
				$encoded = wp_json_encode( $raw_source );
				if ( $encoded !== false ) {
					$raw_json = $encoded;
				}
			}
			$wpdb->replace(
				$p . 'mikrotik_profiles',
				array(
					'name'       => $name,
					'um_id'      => $profile['um_id'] ?? null,
					'rest_id'    => $profile['rest_id'] ?? null,
					'validity'   => $profile['validity'] ?? '',
					'raw'        => $raw_json,
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		if ( ! empty( $assignments ) ) {
			foreach ( $assignments as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				/** @var mixed $user_val */
				$user_val = $row['user'] ?? ( $row['users'] ?? ( $row['name'] ?? '' ) );
				/** @var mixed $profile_val */
				$profile_val = $row['profile'] ?? '';
				$username    = is_scalar( $user_val ) ? (string) $user_val : '';
				$profile     = is_scalar( $profile_val ) ? (string) $profile_val : '';
				if ( '' === $username || '' === $profile ) {
					continue;
				}
				$expires_mysql = null;
				if ( ! empty( $row['till-time'] ) && is_scalar( $row['till-time'] ) ) {
					$expires_ts = strtotime( (string) $row['till-time'] );
					if ( $expires_ts !== false ) {
						$expires_mysql = gmdate( 'Y-m-d H:i:s', $expires_ts );
					}
				}
				$wpdb->replace(
					$p . 'mikrotik_user_profiles',
					array(
						'username'   => $username,
						'profile'    => $profile,
						'um_id'      => isset( $row['.id'] ) && is_scalar( $row['.id'] ) ? (string) $row['.id'] : null,
						'rest_id'    => isset( $row['id'] ) && is_scalar( $row['id'] ) ? (string) $row['id'] : null,
						'expires_at' => $expires_mysql,
						'updated_at' => $now,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}
		}
	}

	/**
	 * @param array<string, mixed> $user_info
	 * @return array{user_id:int}|array{error:array<string, mixed>}
	 */
	protected function ensure_wp_user( string $username, array $user_info ): array {
		$email = $this->build_placeholder_email( $username );
		/** @var mixed $default_role */
		$default_role = get_option( 'default_role', 'subscriber' );
		$role         = $this->has_woocommerce ? 'customer' : ( is_string( $default_role ) ? $default_role : 'subscriber' );
		$userdata     = array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'display_name' => $username,
			'role'         => $role,
		);
		$user_id      = wp_insert_user( $userdata );
		if ( is_wp_error( $user_id ) ) {
			return array(
				'error' => array(
					'username' => $username,
					'message'  => $user_id->get_error_message(),
				),
			);
		}

		update_user_meta( $user_id, 'mumb_um_id', $user_info['um_id'] );
		update_user_meta( $user_id, 'mumb_rest_id', $user_info['rest_id'] );

		return array( 'user_id' => $user_id );
	}

	protected function ensure_placeholder_email( ?WP_User $user ): void {
		if ( ! $user instanceof WP_User ) {
			return;
		}
		if ( ! empty( $user->user_email ) ) {
			return;
		}
		$email = $this->build_placeholder_email( $user->user_login );
		wp_update_user(
			array(
				'ID'         => $user->ID,
				'user_email' => $email,
			)
		);
	}

	/**
	 * @param array<string, mixed> $user_info
	 * @return array<string, mixed>
	 */
	protected function maybe_create_subscription( int $user_id, array $user_info ): array {
		if ( ! $this->has_subscriptions ) {
			return array(
				'status' => 'skipped',
				'reason' => 'subscriptions_plugin_missing',
			);
		}
		$profile = $user_info['profile'] ?? '';
		if ( ! is_string( $profile ) || '' === $profile ) {
			return array(
				'status' => 'skipped',
				'reason' => 'profile_missing',
			);
		}
		if ( ! isset( $this->profile_product_map[ $profile ] ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'profile_not_mapped',
			);
		}
		$product_id = $this->profile_product_map[ $profile ];
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			return array(
				'status' => 'skipped',
				'reason' => 'product_not_found',
			);
		}
		$args         = array(
			'customer_id'      => $user_id,
			'status'           => 'active',
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'start_date'       => gmdate( 'Y-m-d H:i:s' ),
		);
		$subscription = wcs_create_subscription( $args );
		if ( is_wp_error( $subscription ) ) {
			return array(
				'status' => 'error',
				'reason' => $subscription->get_error_message(),
			);
		}
		$subscription->add_product( $product, 1 );
		/** @var mixed $expires */
		$expires = $user_info['expires_at'] ?? null;
		if ( is_scalar( $expires ) && '' !== $expires ) {
			$subscription->set_date( 'end', get_date_from_gmt( (string) $expires ) );
		}
		$subscription->calculate_totals();
		$subscription->save();
		return array(
			'status'          => 'created',
			'subscription_id' => $subscription->get_id(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<string, array<string, mixed>>
	 */
	protected function index_assignments( array $rows ): array {
		$indexed = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			/** @var mixed $user_val */
			$user_val = $row['user'] ?? ( $row['users'] ?? ( $row['name'] ?? '' ) );
			$username = strtolower( is_scalar( $user_val ) ? (string) $user_val : '' );
			if ( '' === $username ) {
				continue;
			}
			$profile = isset( $row['profile'] ) && is_scalar( $row['profile'] ) ? (string) $row['profile'] : '';
			$expires = null;
			if ( ! empty( $row['till-time'] ) && is_scalar( $row['till-time'] ) ) {
				$ts = strtotime( (string) $row['till-time'] );
				if ( $ts ) {
					$expires = gmdate( 'Y-m-d H:i:s', $ts );
				}
			}
			$indexed[ $username ] = array(
				'profile'    => $profile,
				'expires_at' => $expires,
				'um_id'      => isset( $row['.id'] ) && is_scalar( $row['.id'] ) ? (string) $row['.id'] : null,
				'rest_id'    => isset( $row['id'] ) && is_scalar( $row['id'] ) ? (string) $row['id'] : null,
			);
		}
		return $indexed;
	}

	/**
	 * Build MikroTik API wrapper options from saved settings.
	 */
	protected function build_api(): ?MikroTik_API {
		$host     = $this->settings->host;
		$username = $this->settings->username;
		$password = $this->settings->password;
		if ( '' === $host || '' === $username || '' === $password ) {
			return null;
		}
		$ssl  = $this->settings->ssl;
		$port = $this->settings->port;
		if ( $port <= 0 ) {
			$port = $ssl ? 8729 : 8728;
		}
		$opts = array(
			'host'            => $host,
			'port'            => $port,
			'username'        => $username,
			'password'        => $password,
			'ssl'             => $ssl,
			'api_timeout'     => isset( $this->settings->api_timeout ) ? $this->settings->api_timeout : 8,
			'retry_max'       => isset( $this->settings->retry_max ) ? $this->settings->retry_max : 3,
			'retry_base_ms'   => isset( $this->settings->retry_base_ms ) ? $this->settings->retry_base_ms : 200,
			'retry_jitter_ms' => isset( $this->settings->retry_jitter_ms ) ? $this->settings->retry_jitter_ms : 100,
		);
		if ( ! empty( $this->settings->um_rest ) && ! empty( $this->settings->um_rest_base ) ) {
			$opts['um_rest']      = true;
			$opts['um_rest_base'] = $this->settings->um_rest_base;
		}
		try {
			return new MikroTik_API( $opts );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	protected function build_placeholder_email( string $username ): string {
		$sanitized = sanitize_key( $username );
		if ( '' === $sanitized ) {
			$sanitized = 'mikrotik-user';
		}
		/** @var mixed $domain */
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $domain ) || '' === $domain ) {
			$domain = 'example.invalid';
		}
		return $sanitized . '@' . $domain;
	}
}
