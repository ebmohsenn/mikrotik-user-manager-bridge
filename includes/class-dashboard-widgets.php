<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MUMB\Services\SettingsService;

class MUMB_Dashboard_Widgets {

	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
		add_action( 'admin_head-index.php', array( __CLASS__, 'enqueue_styles' ) );
	}

	public static function register(): void {
		$brand = MUMB_Branding::name();
		wp_add_dashboard_widget(
			'mumb_affiliate_snapshot',
			sprintf( __( '%s Affiliates', 'mikrotik-user-manager-bridge' ), $brand ),
			array( __CLASS__, 'render_affiliates' )
		);
		wp_add_dashboard_widget( 'mumb_mikrotik_status', __( 'MikroTik Overview', 'mikrotik-user-manager-bridge' ), array( __CLASS__, 'render_mikrotik' ) );
		wp_add_dashboard_widget(
			'mumb_user_totals',
			sprintf( __( '%s Users', 'mikrotik-user-manager-bridge' ), $brand ),
			array( __CLASS__, 'render_users' )
		);
	}

	public static function render_affiliates(): void {
		$brand = MUMB_Branding::name();
		/** @var array<int, WP_User> $affiliates */
		$affiliates = get_users(
			array(
				'role__in' => array( 'affiliate_partner' ),
				'fields'   => array( 'ID', 'display_name', 'user_login', 'user_email' ),
				'number'   => 200,
			)
		);
		$admins     = get_users(
			array(
				'role__in' => array( 'administrator' ),
				'fields'   => array( 'ID', 'display_name', 'user_login', 'user_email' ),
				'number'   => 200,
			)
		);
		$seen       = array();
		$pool       = array();
		foreach ( array_merge( $affiliates, $admins ) as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			if ( isset( $seen[ $user->ID ] ) ) {
				continue;
			}
			$seen[ $user->ID ] = true;
			$pool[]            = $user;
		}
		$affiliates = $pool;
		$counts     = array();
		foreach ( $affiliates as $affiliate ) {
			if ( ! $affiliate instanceof WP_User ) {
				continue;
			}
			$managed       = new WP_User_Query(
				array(
					'meta_key'   => 'mumb_affiliate_owner',
					'meta_value' => (string) $affiliate->ID,
					'fields'     => 'ID',
				)
			);
			$managed_total = $managed->get_total();
			$counts[]      = array(
				'id'    => $affiliate->ID,
				'name'  => $affiliate->display_name ?: $affiliate->user_login,
				'email' => $affiliate->user_email,
				'count' => $managed_total,
			);
		}
		usort(
			$counts,
			static function ( array $a, array $b ): int {
				return $b['count'] <=> $a['count'];
			}
		);
		$top = $counts[0] ?? null;
		echo '<p>' . esc_html( sprintf( __( '%1$d affiliate partners connected to %2$s.', 'mikrotik-user-manager-bridge' ), count( $counts ), $brand ) ) . '</p>';
		if ( $top ) {
			echo '<p><strong>' . esc_html__( 'Top affiliate', 'mikrotik-user-manager-bridge' ) . ':</strong> ' . esc_html( $top['name'] ) . ' · ' . esc_html( sprintf( __( '%d customers', 'mikrotik-user-manager-bridge' ), $top['count'] ) ) . '</p>';
		}
		if ( empty( $counts ) ) {
			echo '<p>' . esc_html( sprintf( __( 'Invite your first affiliate to start reselling %s plans.', 'mikrotik-user-manager-bridge' ), $brand ) ) . '</p>';
		}
	}

	public static function render_mikrotik(): void {
		/** @var MUMB_Settings|null $settings */
		$settings = null;
		if ( class_exists( 'MUMB_Settings' ) ) {
			$settings = MUMB_Settings::load();
		}
		$state       = SettingsService::get_connection_state();
		$status_text = isset( $state['status'] ) && is_scalar( $state['status'] ) ? (string) $state['status'] : '';
		$timestamp   = isset( $state['timestamp'] ) && is_numeric( $state['timestamp'] ) ? (int) $state['timestamp'] : 0;
		echo '<ul class="mumb-widget-list">';
		if ( $settings instanceof MUMB_Settings ) {
			$host_display = $settings->host !== '' ? $settings->host : __( 'Not configured', 'mikrotik-user-manager-bridge' );
			$user_display = $settings->username !== '' ? $settings->username : '—';
			echo '<li><strong>' . esc_html__( 'Router host', 'mikrotik-user-manager-bridge' ) . ':</strong> ' . esc_html( $host_display ) . '</li>';
			echo '<li><strong>' . esc_html__( 'API user', 'mikrotik-user-manager-bridge' ) . ':</strong> ' . esc_html( $user_display ) . '</li>';
		}
		if ( '' !== $status_text ) {
			echo '<li><strong>' . esc_html__( 'Last status', 'mikrotik-user-manager-bridge' ) . ':</strong> ' . esc_html( $status_text ) . '</li>';
		}
		if ( $timestamp > 0 ) {
			/** @var string $date_format */
			$date_format = get_option( 'date_format' );
			/** @var string $time_format */
			$time_format = get_option( 'time_format' );
			$formatted   = wp_date( $date_format . ' ' . $time_format, $timestamp );
			if ( is_string( $formatted ) ) {
				echo '<li><strong>' . esc_html__( 'Updated', 'mikrotik-user-manager-bridge' ) . ':</strong> ' . esc_html( $formatted ) . '</li>';
			}
		}
		echo '</ul>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=mwp_manager' ) ) . '" class="button button-secondary">' . esc_html__( 'Open MikroTik Manager', 'mikrotik-user-manager-bridge' ) . '</a></p>';
	}

	public static function render_users(): void {
		$brand = MUMB_Branding::name();
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}
		/** @var string $prefix */
		$prefix      = $wpdb->prefix;
		$user_counts = count_users();
		$total_wp    = $user_counts['total_users'];
		/** @var string|null $um_query */
		$um_query = $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $prefix . 'mikrotik_users' );
		$um_value = is_string( $um_query ) ? $wpdb->get_var( $um_query ) : null;
		$total_um = is_numeric( $um_value ) ? (int) $um_value : 0;
		/** @var string|null $profiles_query */
		$profiles_query = $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $prefix . 'mikrotik_profiles' );
		$profiles_value = is_string( $profiles_query ) ? $wpdb->get_var( $profiles_query ) : null;
		$total_profiles = is_numeric( $profiles_value ) ? (int) $profiles_value : 0;
		echo '<ul class="mumb-widget-list">';
		echo '<li><strong>' . esc_html__( 'WordPress users', 'mikrotik-user-manager-bridge' ) . ':</strong> ' . esc_html( (string) $total_wp ) . '</li>';
		echo '<li><strong>' . esc_html__( 'MikroTik synced users', 'mikrotik-user-manager-bridge' ) . ':</strong> ' . esc_html( (string) $total_um ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Available profiles', 'mikrotik-user-manager-bridge' ) . ':</strong> ' . esc_html( (string) $total_profiles ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html( sprintf( __( 'Use the dashboard tabs to investigate %s mismatches or assign profiles manually.', 'mikrotik-user-manager-bridge' ), $brand ) ) . '</p>';
	}

	public static function enqueue_styles(): void {
		echo '<style>.mumb-widget-list{margin:0;padding-left:18px;} .mumb-widget-list li{margin-bottom:6px;} #mumb_mikrotik_status .button{margin-top:10px;}</style>';
	}
}

MUMB_Dashboard_Widgets::init();
