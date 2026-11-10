<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MUMB\Services\SettingsService;

class MUMB_Affiliates {

	/**
	 * @return array<int, string>
	 */
	public static function current_user_groups(): array {
		if ( ! is_user_logged_in() ) {
			return array();
		}
		$user   = wp_get_current_user();
		$groups = self::extract_groups( get_user_meta( $user->ID, 'mumb_affiliate_groups', true ) );
		if ( empty( $groups ) ) {
			$auto = self::maybe_auto_assign_default_group( $user );
			if ( ! empty( $auto ) ) {
				$groups = $auto;
			}
		}
		return $groups;
	}

	public static function user_is_affiliate(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();

		// Treat administrators as affiliates
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}

		/** @var array<int, string> $roles */
		$roles = $user->roles;
		if ( in_array( 'affiliate_partner', $roles, true ) ) {
			return true;
		}
		// Remove group-based affiliate detection - affiliates are now role-based only
		return false;
	}

	/**
	 * @param array<int|string, string|int|float|bool|null> $groups
	 */
	public static function store_user_groups( int $user_id, array $groups ): void {
		$clean = self::sanitize_group_list( array_values( $groups ) );
		update_user_meta( $user_id, 'mumb_affiliate_groups', wp_json_encode( $clean ) );
	}

	/**
	 * @return array<int, string>
	 */
	public static function get_user_groups( int $user_id ): array {
		return self::extract_groups( get_user_meta( $user_id, 'mumb_affiliate_groups', true ) );
	}

	public static function clear_user_groups( int $user_id ): void {
		delete_user_meta( $user_id, 'mumb_affiliate_groups' );
	}

	/**
	 * @param WP_User|int|string|null $user
	 */
	public static function is_affiliate_user( $user ): bool {
		if ( $user instanceof WP_User ) {
			$target = $user;
		} elseif ( null === $user || '' === $user ) {
			return false;
		} else {
			$target = get_user_by( 'id', (int) $user );
		}
		if ( ! $target instanceof WP_User ) {
			return false;
		}

		// Treat administrators as affiliates
		if ( user_can( $target, 'manage_options' ) ) {
			return true;
		}

		/** @var array<int, string> $roles */
		$roles = $target->roles;
		if ( in_array( 'affiliate_partner', $roles, true ) ) {
			return true;
		}
		// Remove group-based affiliate detection - affiliates are now role-based only
		return false;
	}

	/**
	 * Attempt to assign a default affiliate group when none are present.
	 *
	 * @return array<int, string>
	 */
	private static function maybe_auto_assign_default_group( WP_User $user ): array {
		if ( ! $user->exists() ) {
			return array();
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return array();
		}
		$default = self::resolve_default_group();
		if ( null === $default ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log(
					'debug',
					'affiliate_auto_group_skip',
					'No default affiliate group available for auto-assignment',
					array(
						'user_id'  => $user->ID,
						'username' => $user->user_login,
					)
				);
			}
			return array();
		}
		self::store_user_groups( $user->ID, array( $default ) );
		if ( class_exists( 'MUMB_Logger' ) ) {
			MUMB_Logger::log(
				'info',
				'affiliate_auto_group',
				'Assigned default group to affiliate',
				array(
					'user_id'  => $user->ID,
					'username' => $user->user_login,
					'group'    => $default,
				)
			);
		}
		return array( $default );
	}

	/**
	 * Resolve the default affiliate group name.
	 *
	 * @return string|null The default group name or null if none found.
	 */
	private static function resolve_default_group(): ?string {
		static $resolved = false;
		/** @var string|null $value */
		static $value = null;

		if ( $resolved ) {
			return $value;
		}
		$resolved = true;

		$option = SettingsService::get_affiliate_default_group();
		if ( '' !== $option ) {
			$value = $option;
		}

		/**
		 * Filter the default group assigned to affiliates that have none.
		 *
		 * @param string|null $group
		 */
		/** @var string|null $filtered_value */
		$filtered_value = apply_filters( 'mumb_affiliate_default_group', $value );

		if ( is_string( $filtered_value ) ) {
			$value = $filtered_value;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			/** @psalm-suppress InvalidGlobal */
			global $wpdb;
			if ( $wpdb instanceof wpdb ) {
				/** @var string $p */
				$p         = $wpdb->prefix;
				$candidate = null;
				/** @var string|false $sql */
				$sql = $wpdb->prepare( 'SELECT name FROM %i ORDER BY name ASC LIMIT 1', $p . 'mikrotik_groups' );
				if ( is_string( $sql ) ) {
					$row = $wpdb->get_var( $sql );
					if ( is_string( $row ) ) {
						$candidate = $row;
					}
				}
				if ( is_string( $candidate ) && '' !== $candidate ) {
					$value = $candidate;
				}
			}
		}

		if ( is_string( $value ) && '' !== $value ) {
			/** @psalm-suppress InvalidGlobal */
			global $wpdb;
			if ( $wpdb instanceof wpdb ) {
				/** @var string $p */
				$p = $wpdb->prefix;
				/** @var string|false $sql */
				$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE name=%s', $p . 'mikrotik_groups', $value );
				if ( is_string( $sql ) ) {
					/** @var string|null $count_result */
					$count_result = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					if ( ! is_string( $count_result ) || (int) $count_result <= 0 ) {
						$value = null;
					}
				}
			}
		} else {
			$value = null;
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 * @return array<int, string>
	 */
	private static function extract_groups( $value ): array {
		$raw = $value;
		if ( is_string( $raw ) ) {
			/** @var array<array-key, mixed>|null $decoded */
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = array_values( $decoded );
			} else {
				$raw = array();
			}
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return self::sanitize_group_list( array_values( $raw ) );
	}

	/**
	 * @param array<int|string, mixed> $groups
	 * @return array<int, string>
	 */
	private static function sanitize_group_list( array $groups ): array {
		$clean = array();
		/** @var mixed $group */
		foreach ( $groups as $group ) {
			if ( is_scalar( $group ) ) {
				$value = trim( (string) $group );
				if ( '' !== $value ) {
					$clean[] = $value;
				}
			}
		}
		return array_values( array_unique( $clean ) );
	}
}
