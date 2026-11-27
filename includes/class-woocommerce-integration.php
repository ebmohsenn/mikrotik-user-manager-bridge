<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MUMB\Services\SettingsService;

class MUMB_Woo_Integration {

	/** @var array<string,int> */
	protected static array $profile_product_map = array();

	public static function init(): void {
		// class_exists() is sufficient here; is_plugin_active() is only loaded in wp-admin and
		// would fatal on front-end requests.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		self::$profile_product_map = self::get_profile_product_map();
		if ( empty( self::$profile_product_map ) ) {
			// Still register hooks so manual enqueues work, but nothing to map.
		}
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'handle_order_completed' ), 10, 1 );
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( __CLASS__, 'handle_subscription_renewal' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_cancelled', array( __CLASS__, 'handle_subscription_cancelled' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_expired', array( __CLASS__, 'handle_subscription_cancelled' ), 10, 1 );
		add_action( 'password_reset', array( __CLASS__, 'handle_password_reset' ), 10, 2 );
		add_action( 'profile_update', array( __CLASS__, 'handle_profile_update' ), 10, 2 );
	}

	/**
	 * @return array<string,int>
	 */
	protected static function get_profile_product_map(): array {
		$raw = SettingsService::get_profile_map();
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$map = array();
		foreach ( $raw as $profile => $product_id ) {
			/** @var mixed $product_id */
			if ( ! is_string( $profile ) || '' === $profile ) {
				continue;
			}
			if ( is_numeric( $product_id ) ) {
				$map[ $profile ] = (int) $product_id;
			}
		}
		return $map;
	}

	public static function handle_order_completed( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		/** @var mixed $user_id */
		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}
		$user = get_user_by( 'id', is_numeric( $user_id ) ? (int) $user_id : 0 );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$username = $user->user_login;
		if ( '' === $username ) {
			return;
		}
		$profile = self::determine_profile_from_items( $order->get_items() );
		if ( ! $profile ) {
			return;
		}
		$expires_at = self::determine_expiry_from_subscription( $order );
		self::enqueue_job(
			'create_user',
			$username,
			$profile,
			array(
				'comment'    => sprintf( 'WooCommerce order #%d', $order->get_id() ),
				'expires_at' => $expires_at,
			)
		);
	}

	/**
	 * @param int|object $subscription
	 */
	public static function handle_subscription_renewal( $subscription ): void {
		if ( ! class_exists( 'WC_Subscription' ) ) {
			return;
		}
		if ( is_numeric( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );
		}
		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}
		/** @var mixed $user_id */
		$user_id = $subscription->get_user_id();
		if ( ! $user_id ) {
			return;
		}
		$user = get_user_by( 'id', is_numeric( $user_id ) ? (int) $user_id : 0 );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$username = $user->user_login;
		if ( '' === $username ) {
			return;
		}
		$profile = self::determine_profile_from_items( $subscription->get_items() );
		if ( ! $profile ) {
			return;
		}
		/** @var mixed $expires_at */
		$expires_at = method_exists( $subscription, 'get_date' ) ? $subscription->get_date( 'end' ) : null;
		self::enqueue_job(
			'update_user',
			$username,
			$profile,
			array(
				'expires_at'      => $expires_at,
				'subscription_id' => $subscription->get_id(),
			)
		);
	}

	/**
	 * @param int|object $subscription
	 */
	public static function handle_subscription_cancelled( $subscription ): void {
		if ( ! class_exists( 'WC_Subscription' ) ) {
			return;
		}
		if ( is_numeric( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );
		}
		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}
		/** @var mixed $user_id */
		$user_id = $subscription->get_user_id();
		if ( ! $user_id ) {
			return;
		}
		$user = get_user_by( 'id', is_numeric( $user_id ) ? (int) $user_id : 0 );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$username = $user->user_login;
		if ( '' === $username ) {
			return;
		}
		$profile = self::determine_profile_from_items( $subscription->get_items() );
		if ( null === $profile ) {
			return;
		}
		self::enqueue_job(
			'disable_user',
			$username,
			$profile,
			array(
				'subscription_id' => $subscription->get_id(),
				'remove_profiles' => true,
			)
		);
	}

	public static function handle_password_reset( WP_User $user, string $new_password ): void {
		$username = $user->user_login;
		if ( '' === $username || ! self::has_router_account( $username ) ) {
			return;
		}
		self::enqueue_job( 'update_user', $username, '', array( 'password' => $new_password ) );
	}

	public static function handle_profile_update( int $user_id, WP_User $old_user_data ): void {
		if ( empty( $_POST['pass1'] ) || ! is_string( $_POST['pass1'] ) ) {
			return;
		}
		$pass1 = wp_unslash( $_POST['pass1'] );
		$pass2 = isset( $_POST['pass2'] ) && is_string( $_POST['pass2'] ) ? wp_unslash( $_POST['pass2'] ) : $pass1;
		/** @psalm-suppress DocblockTypeContradiction */
		if ( '' === $pass1 || $pass1 !== $pass2 ) {
			return;
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$username = $user->user_login;
		if ( '' === $username || ! self::has_router_account( $username ) ) {
			return;
		}
		self::enqueue_job( 'update_user', $username, '', array( 'password' => $pass1 ) );
	}

	/**
	 * Whether the mirror table knows a router user with this login. Password changes of WordPress
	 * accounts that have no router user (administrators, editors) are none of the router's business
	 * and must not be queued.
	 */
	protected static function has_router_account( string $username ): bool {
		global $wpdb;
		if ( ! ( $wpdb instanceof wpdb ) ) {
			return false;
		}
		/** @var string|null $query */
		$query = $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mikrotik_users WHERE username=%s', $username ); // @phpstan-ignore-line
		return (int) $wpdb->get_var( $query ) > 0;
	}

	/**
	 * @param Traversable<mixed>|array<mixed> $items
	 */
	protected static function determine_profile_from_items( $items ): ?string {
		if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
			return null;
		}
		foreach ( $items as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product_ids = array_filter(
				array(
					$item->get_variation_id(),
					$item->get_product_id(),
				)
			);
			foreach ( $product_ids as $product_id ) {
				/** @var mixed $product_id */
				if ( is_numeric( $product_id ) ) {
					$profile = self::profile_for_product( (int) $product_id );
					if ( $profile ) {
						return $profile;
					}
				}
			}
		}
		return null;
	}

	protected static function determine_expiry_from_subscription( WC_Order $order ): ?string {
		if ( ! class_exists( 'WC_Subscription' ) || ! function_exists( 'wcs_order_contains_subscription' ) ) {
			return null;
		}
		if ( ! wcs_order_contains_subscription( $order, 'renewal' ) && ! wcs_order_contains_subscription( $order ) ) {
			return null;
		}
		$subscriptions = wcs_get_subscriptions_for_order( $order );
		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription instanceof WC_Subscription ) {
				continue;
			}
			$date = method_exists( $subscription, 'get_date' ) ? $subscription->get_date( 'end' ) : null;
			if ( $date ) {
				return $date;
			}
		}
		return null;
	}

	protected static function profile_for_product( int $product_id ): ?string {
		foreach ( self::$profile_product_map as $profile => $mapped_id ) {
			if ( $mapped_id === $product_id ) {
				return $profile;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	protected static function enqueue_job( string $type, string $username, string $profile, array $extra = array() ): void {
		$payload = array(
			'type' => $type,
			'user' => $username,
		);

		if ( '' !== $profile ) {
			$payload['profile'] = $profile;
		}

		if ( ! empty( $extra ) ) {
			$payload['data'] = $extra;
		}

		global $wpdb;
		/** @var string $p */
		$p = $wpdb->prefix;
		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT um_id, rest_id FROM ' . $p . 'mikrotik_users WHERE username=%s LIMIT 1', $username ), ARRAY_A );
		if ( is_array( $row ) ) {
			if ( ! isset( $payload['data'] ) ) {
				$payload['data'] = array();
			}
			if ( ! empty( $row['um_id'] ) ) {
				$payload['data']['um_id'] = is_scalar( $row['um_id'] ) ? (string) $row['um_id'] : '';
			}
			if ( ! empty( $row['rest_id'] ) ) {
				$payload['data']['rest_id'] = is_scalar( $row['rest_id'] ) ? (string) $row['rest_id'] : '';
			}
		}

		$key = sprintf( 'wc_%s_%s_%s', $type, strtolower( $username ), '' !== $profile ? strtolower( $profile ) : 'none' );
		MUMB_Queue::enqueue( $key, $payload );
	}
}
