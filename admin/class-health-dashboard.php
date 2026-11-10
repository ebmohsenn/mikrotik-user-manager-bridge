<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUMB_Health_Dashboard_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		add_submenu_page(
			'mwp_manager',
			__( 'Health Dashboard', 'mikrotik-user-manager-bridge' ),
			__( 'Health Dashboard', 'mikrotik-user-manager-bridge' ),
			'manage_options',
			'mumb_health_dashboard',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mikrotik-user-manager-bridge' ) );
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Health Dashboard', 'mikrotik-user-manager-bridge' ) . '</h1>';
		echo '<div id="mumb-health-dashboard-root"></div>';
		echo '</div>';
	}

	public static function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'page_mumb_health_dashboard' ) === false ) {
			return;
		}

		$handle      = 'mumb-health-dashboard';
		$deps        = array();
		$script_path = plugin_dir_path( MUMB_FILE ) . 'admin/health-dashboard/app.js';
		$script_url  = plugins_url( 'admin/health-dashboard/app.js', MUMB_FILE );
		$style_path  = plugin_dir_path( MUMB_FILE ) . 'admin/health-dashboard/app.css';
		$style_url   = plugins_url( 'admin/health-dashboard/app.css', MUMB_FILE );
		$mtime       = @filemtime( $script_path );
		$version     = $mtime ? (string) $mtime : ( defined( 'MUMB_VERSION' ) ? (string) MUMB_VERSION : 'dev' );
		wp_register_script(
			$handle,
			$script_url,
			$deps,
			$version,
			true
		);
		if ( file_exists( $style_path ) ) {
			$css_mtime = @filemtime( $style_path );
			$css_ver   = $css_mtime ? (string) $css_mtime : $version;
			wp_register_style(
				$handle,
				$style_url,
				array(),
				$css_ver
			);
		}

		$strings = array(
			'title'          => __( 'Health Dashboard', 'mikrotik-user-manager-bridge' ),
			'filterRole'     => __( 'Role', 'mikrotik-user-manager-bridge' ),
			'filterStatus'   => __( 'Status', 'mikrotik-user-manager-bridge' ),
			'filterSearch'   => __( 'Search servers…', 'mikrotik-user-manager-bridge' ),
			'allRoles'       => __( 'All roles', 'mikrotik-user-manager-bridge' ),
			'allStatuses'    => __( 'All statuses', 'mikrotik-user-manager-bridge' ),
			'btnRefreshAll'  => __( 'Refresh Now', 'mikrotik-user-manager-bridge' ),
			'btnRefreshRow'  => __( 'Refresh', 'mikrotik-user-manager-bridge' ),
			'lastChecked'    => __( 'Last checked', 'mikrotik-user-manager-bridge' ),
			'lastUpdatedAt'  => __( 'Last updated %s', 'mikrotik-user-manager-bridge' ),
			'latency'        => __( 'Latency', 'mikrotik-user-manager-bridge' ),
			'activeUsers'    => __( 'Active users', 'mikrotik-user-manager-bridge' ),
			'uptime'         => __( 'Uptime', 'mikrotik-user-manager-bridge' ),
			'cpuLoad'        => __( 'CPU load', 'mikrotik-user-manager-bridge' ),
			'memoryUsage'    => __( 'Memory usage', 'mikrotik-user-manager-bridge' ),
			'bandwidth'      => __( 'Bandwidth', 'mikrotik-user-manager-bridge' ),
			'name'           => __( 'Name', 'mikrotik-user-manager-bridge' ),
			'hostname'       => __( 'Hostname', 'mikrotik-user-manager-bridge' ),
			'role'           => __( 'Role', 'mikrotik-user-manager-bridge' ),
			'status'         => __( 'Status', 'mikrotik-user-manager-bridge' ),
			'actions'        => __( 'Actions', 'mikrotik-user-manager-bridge' ),
			'noData'         => __( 'No servers found.', 'mikrotik-user-manager-bridge' ),
			'loading'        => __( 'Loading…', 'mikrotik-user-manager-bridge' ),
			'chartLatency'   => __( 'Latency trend', 'mikrotik-user-manager-bridge' ),
			'chartActive'    => __( 'Active users trend', 'mikrotik-user-manager-bridge' ),
			'updatedJustNow' => __( 'Updated just now', 'mikrotik-user-manager-bridge' ),
			'error'          => __( 'Something went wrong.', 'mikrotik-user-manager-bridge' ),
			'selectPrompt'   => __( 'Select a server to view detailed metrics.', 'mikrotik-user-manager-bridge' ),
			'detailsTitle'   => __( 'Server details', 'mikrotik-user-manager-bridge' ),
			'legendLatency'  => __( 'Latency (ms)', 'mikrotik-user-manager-bridge' ),
			'legendActive'   => __( 'Active users', 'mikrotik-user-manager-bridge' ),
			'historyEmpty'   => __( 'No recent history available.', 'mikrotik-user-manager-bridge' ),
			'refreshing'     => __( 'Refreshing…', 'mikrotik-user-manager-bridge' ),
			'statusOnline'   => __( 'Online', 'mikrotik-user-manager-bridge' ),
			'statusDegraded' => __( 'Degraded', 'mikrotik-user-manager-bridge' ),
			'statusOffline'  => __( 'Offline', 'mikrotik-user-manager-bridge' ),
		);

		wp_localize_script(
			$handle,
			'MUMB_HEALTH_DASHBOARD',
			array(
				'restBase'      => esc_url_raw( rest_url( 'mikrotik/v1' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'currentTime'   => current_time( 'mysql', true ),
				'pollInterval'  => 60000,
				'strings'       => $strings,
			)
		);

		wp_enqueue_script( $handle );
		if ( wp_style_is( $handle, 'registered' ) ) {
			wp_enqueue_style( $handle );
		}
	}
}
