<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MWP_Admin {

	private static function plugin_base_dir(): string {
		/** @var string $base */
		$base = defined( 'MUMB_DIR' ) && is_string( MUMB_DIR ) ? MUMB_DIR : dirname( __DIR__ ) . '/';
		return rtrim( $base, '/\\' ) . '/';
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	protected static function use_react_ui(): bool {
		// Default to enabled unless explicitly disabled via option/filter
		/** @var mixed $raw */
		$raw = get_option( 'mwp_use_react', 1 );
		$opt = is_numeric( $raw ) ? (int) $raw : 1;
		/** @var mixed $filtered */
		$filtered = apply_filters( 'mwp_use_react_ui', $opt );
		return (bool) $filtered;
	}

	public static function menu(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::use_react_ui() ) {
			return;
		}
		$brand      = MUMB_Branding::name();
		$page_title = sprintf( __( '%s Manager', 'mikrotik-user-manager-bridge' ), $brand );
		add_menu_page(
			$page_title,
			$page_title,
			'manage_options',
			'mwp_manager',
			array( __CLASS__, 'render' ),
			'dashicons-networking',
			61
		);
	}

	public static function render(): void {
		$branding   = MUMB_Branding::to_array();
		$brand_name = $branding['name'] !== '' ? $branding['name'] : MUMB_Branding::defaults()['name'];
		$brand_logo = $branding['logo_url'];
		$manager_label = sprintf( __( '%s Manager', 'mikrotik-user-manager-bridge' ), $brand_name );
		echo '<div class="wrap mwp-admin-wrap">';
		echo '<header class="mwp-admin-header">';
		if ( $brand_logo ) {
			echo '<div class="mwp-admin-header__logo"><img src="' . esc_url( $brand_logo ) . '" alt="' . esc_attr( $brand_name ) . '" /></div>';
		}
		echo '<div class="mwp-admin-header__content">';
		echo '<span class="mwp-admin-header__eyebrow">' . esc_html__( 'Admin Dashboard', 'mikrotik-user-manager-bridge' ) . '</span>';
		echo '<h1 class="mwp-admin-header__title">' . esc_html( $manager_label ) . '</h1>';
		echo '<p class="mwp-admin-header__description">' . esc_html__( 'Centralize MikroTik health, users, and billing in one place.', 'mikrotik-user-manager-bridge' ) . '</p>';
		echo '</div>';
		echo '</header>';
		echo '<div id="mwp-admin-root"></div>';
		$build_dir = self::plugin_base_dir() . 'admin/build/index.js';
		if ( ! file_exists( $build_dir ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'React build not found. Run the admin build to load the new UI.', 'mikrotik-user-manager-bridge' ) . '</p></div>';
		}
		echo '</div>';
	}

	public static function enqueue( string $hook ): void {
		if ( ! self::use_react_ui() ) {
			return;
		}
		if ( strpos( $hook, 'page_mwp_manager' ) === false ) {
			return;
		}
		$build_path = self::plugin_base_dir() . 'admin/build/index.js';
		$build_url  = plugins_url( '../admin/build/index.js', __FILE__ );
		if ( file_exists( $build_path ) ) {
			$mtime_raw = @filemtime( $build_path );
			$mtime     = $mtime_raw !== false ? $mtime_raw : 0;
			$ver       = $mtime > 0 ? (string) $mtime : ( defined( 'MUMB_VERSION' ) ? (string) MUMB_VERSION : 'dev' );
			// Depend on wp-element so WP ensures React is available if needed
			wp_enqueue_script( 'mwp-admin', $build_url, array( 'wp-element' ), $ver, true );
			// If Vite outputs a CSS file alongside JS, enqueue it as well with cache-busting
			$css_path = self::plugin_base_dir() . 'admin/build/style.css';
			if ( file_exists( $css_path ) ) {
				$css_url       = plugins_url( '../admin/build/style.css', __FILE__ );
				$css_mtime_raw = @filemtime( $css_path );
				$css_mtime     = $css_mtime_raw !== false ? $css_mtime_raw : 0;
				$css_ver       = $css_mtime > 0 ? (string) $css_mtime : $ver;
				wp_enqueue_style( 'mwp-admin', $css_url, array(), $css_ver );
			}
			$hash = null;
			if ( function_exists( 'md5_file' ) ) {
				$h = @md5_file( $build_path );
				if ( $h ) {
					$hash = substr( $h, 0, 8 );
				}
			}
			// Default readable time using WordPress timezone + site formats
			/** @var string $date_format */
			$date_format = get_option( 'date_format' );
			/** @var string $time_format */
			$time_format = get_option( 'time_format' );
			$readable    = $mtime > 0 ? wp_date( $date_format . ' ' . $time_format, $mtime ) : '';

			// If a CI-generated build metadata file exists, prefer its values for timestamp/hash
			$meta      = null;
			$meta_path = self::plugin_base_dir() . 'admin/build/build-meta.json';
			if ( file_exists( $meta_path ) ) {
				$raw = @file_get_contents( $meta_path );
				if ( $raw !== false ) {
					/** @var mixed $j */
					$j = json_decode( $raw, true );
					if ( is_array( $j ) ) {
						$meta = $j;
						if ( ! empty( $j['timestamp'] ) && is_numeric( $j['timestamp'] ) ) {
							$mtime = (int) $j['timestamp'];
						}
						// Always format using WP timezone and site formats
						/** @var string $date_format */
						$date_format = get_option( 'date_format' );
						/** @var string $time_format */
						$time_format = get_option( 'time_format' );
						$readable    = $mtime > 0 ? wp_date( $date_format . ' ' . $time_format, $mtime ) : $readable;
						if ( ! empty( $j['short'] ) && is_string( $j['short'] ) ) {
							$hash = $j['short'];
						}
					}
				}
			}

			// UI badge config (filterable)
			$badge_cfg = array(
				'show'  => true,
				'text'  => 'Deployed %time% · %hash%',
				'link'  => 'run', // 'run' | 'commit' | ''
				'style' => array(
					'bg'     => '#eef6ff',
					'fg'     => '#1e40af',
					'border' => '#bfdbfe',
				),
			);
			/** @var array<string, mixed> $badge_cfg */
			$badge_cfg = apply_filters( 'mwp_ui_badge_config', $badge_cfg );

			wp_localize_script(
				'mwp-admin',
				'MWP_BOOTSTRAP',
				array(
					'rest'        => array(
						'mikrotikBase' => esc_url_raw( rest_url( 'mikrotik/v1' ) ),
						'mwpBase'      => esc_url_raw( rest_url( 'mwp/v1' ) ),
						'nonce'        => wp_create_nonce( 'wp_rest' ),
					),
					'currentUser' => array( 'id' => get_current_user_id() ),
					'version'     => defined( 'MUMB_VERSION' ) ? MUMB_VERSION : 'dev',
					'build'       => array(
						'mtime' => $mtime,
						'time'  => $readable,
						'hash'  => $hash,
						'meta'  => $meta,
					),
					'ui'          => array( 'badge' => $badge_cfg ),
					'branding'    => MUMB_Branding::to_array(),
					'i18n'        => self::i18n_strings(),
				)
			);
		}
		$inline_css = <<<'CSS'
.toplevel_page_mwp_manager #wpbody-content .wrap > h1.wp-heading-inline,
.toplevel_page_mwp_manager #wpbody-content .wrap > hr.wp-header-end {
	display: none;
}
.toplevel_page_mwp_manager .mwp-admin-wrap {
	margin-top: 0;
}
.toplevel_page_mwp_manager .mwp-admin-header {
	display: flex;
	align-items: center;
	gap: 20px;
	margin-bottom: 24px;
	padding: 24px 28px;
	border-radius: 16px;
	background: linear-gradient(135deg, #f8fafc, #e2e8f0);
	border: 1px solid #e2e8f0;
	box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
}
.toplevel_page_mwp_manager .mwp-admin-header__logo img {
	max-height: 60px;
	width: auto;
	display: block;
}
.toplevel_page_mwp_manager .mwp-admin-header__content {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.toplevel_page_mwp_manager .mwp-admin-header__eyebrow {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: rgba(99, 102, 241, 0.12);
	color: #4338ca;
	padding: 4px 10px;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.08em;
}
.toplevel_page_mwp_manager .mwp-admin-header__title {
	margin: 0;
	font-size: 1.65rem;
	line-height: 1.2;
	font-weight: 700;
	color: #0f172a;
}
.toplevel_page_mwp_manager .mwp-admin-header__description {
	margin: 0;
	color: #475569;
	font-size: 0.98rem;
}
@media (max-width: 782px) {
	.toplevel_page_mwp_manager .mwp-admin-header {
		flex-direction: column;
		align-items: flex-start;
		padding: 20px;
	}
	.toplevel_page_mwp_manager .mwp-admin-header__logo img {
		max-height: 48px;
	}
	.toplevel_page_mwp_manager .mwp-admin-header__title {
		font-size: 1.4rem;
	}
}
CSS;
		if ( wp_style_is( 'mwp-admin', 'enqueued' ) ) {
			wp_add_inline_style( 'mwp-admin', $inline_css );
		} else {
			/** @var string $version */
			$version = defined( 'MUMB_VERSION' ) ? MUMB_VERSION : 'dev';
			wp_register_style( 'mwp-admin-inline', false, array(), $version );
			wp_enqueue_style( 'mwp-admin-inline' );
			wp_add_inline_style( 'mwp-admin-inline', $inline_css );
		}
	}

	/**
	 * @return array<string, string>
	 */
	private static function i18n_strings(): array {
		return array(
			'Dashboard'              => __( 'Dashboard', 'mikrotik-user-manager-bridge' ),
			'Users'                  => __( 'Users', 'mikrotik-user-manager-bridge' ),
			'Profiles'               => __( 'Profiles', 'mikrotik-user-manager-bridge' ),
			'Groups'                 => __( 'Groups', 'mikrotik-user-manager-bridge' ),
			'User Profiles'          => __( 'User Profiles', 'mikrotik-user-manager-bridge' ),
			'Subscriptions'          => __( 'Subscriptions', 'mikrotik-user-manager-bridge' ),
			'Locations'              => __( 'Locations', 'mikrotik-user-manager-bridge' ),
			'Subscription Status'    => __( 'Subscription Status', 'mikrotik-user-manager-bridge' ),
			'Routers'                => __( 'Routers', 'mikrotik-user-manager-bridge' ),
			'Affiliates'             => __( 'Affiliates', 'mikrotik-user-manager-bridge' ),
			'Logs'                   => __( 'Logs', 'mikrotik-user-manager-bridge' ),
			'Settings'               => __( 'Settings', 'mikrotik-user-manager-bridge' ),
			'New User'               => __( 'New User', 'mikrotik-user-manager-bridge' ),
			'Refresh Groups'         => __( 'Refresh Groups', 'mikrotik-user-manager-bridge' ),
			'Groups refreshed'       => __( 'Groups refreshed', 'mikrotik-user-manager-bridge' ),
			'Search users…'          => __( 'Search users…', 'mikrotik-user-manager-bridge' ),
			'Force Refresh'          => __( 'Force Refresh', 'mikrotik-user-manager-bridge' ),
			'Live profiles'          => __( 'Live profiles', 'mikrotik-user-manager-bridge' ),
			'Create User'            => __( 'Create User', 'mikrotik-user-manager-bridge' ),
			'Cancel'                 => __( 'Cancel', 'mikrotik-user-manager-bridge' ),
			'Edit'                   => __( 'Edit', 'mikrotik-user-manager-bridge' ),
			'Delete'                 => __( 'Delete', 'mikrotik-user-manager-bridge' ),
			'Reset Password'         => __( 'Reset Password', 'mikrotik-user-manager-bridge' ),
			'Username'               => __( 'Username', 'mikrotik-user-manager-bridge' ),
			'Group'                  => __( 'Group', 'mikrotik-user-manager-bridge' ),
			'Shared'                 => __( 'Shared', 'mikrotik-user-manager-bridge' ),
			'Comment'                => __( 'Comment', 'mikrotik-user-manager-bridge' ),
			'Status'                 => __( 'Status', 'mikrotik-user-manager-bridge' ),
			'Actions'                => __( 'Actions', 'mikrotik-user-manager-bridge' ),
			'Affiliate column'       => __( 'Affiliate', 'mikrotik-user-manager-bridge' ),
			'Not assigned'           => __( 'Not assigned', 'mikrotik-user-manager-bridge' ),
			'No users'               => __( 'No users.', 'mikrotik-user-manager-bridge' ),
			'Affiliates heading'     => __( 'Affiliates', 'mikrotik-user-manager-bridge' ),
			'Affiliate profile'      => __( 'Affiliate profile', 'mikrotik-user-manager-bridge' ),
			'Affiliate email'        => __( 'Email address', 'mikrotik-user-manager-bridge' ),
			'Affiliate username'     => __( 'Affiliate username', 'mikrotik-user-manager-bridge' ),
			'Allowed groups'         => __( 'Allowed groups', 'mikrotik-user-manager-bridge' ),
			'Display name'           => __( 'Display name', 'mikrotik-user-manager-bridge' ),
			'Add affiliate'          => __( 'Add affiliate', 'mikrotik-user-manager-bridge' ),
			'Update affiliate'       => __( 'Update affiliate', 'mikrotik-user-manager-bridge' ),
			'Clear'                  => __( 'Clear', 'mikrotik-user-manager-bridge' ),
			'No affiliates yet.'     => __( 'No affiliates yet.', 'mikrotik-user-manager-bridge' ),
			'Users badge label'      => __( 'Users', 'mikrotik-user-manager-bridge' ),
			'Rate limited msg'       => __( 'Rate limited', 'mikrotik-user-manager-bridge' ),
			'Affiliate instructions' => __( 'Provide username + email to create a new affiliate.', 'mikrotik-user-manager-bridge' ),
			'Editing affiliate'      => __( 'Editing affiliate', 'mikrotik-user-manager-bridge' ),
			'Name'                   => __( 'Name', 'mikrotik-user-manager-bridge' ),
			'Remove'                 => __( 'Remove', 'mikrotik-user-manager-bridge' ),
			'MikroTik Manager'       => __( 'MikroTik Manager', 'mikrotik-user-manager-bridge' ),
		);
	}
}

MWP_Admin::init();
