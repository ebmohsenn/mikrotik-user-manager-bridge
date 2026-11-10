<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MUMB\Services\SettingsService;

class MUMB_Profile_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
	}

	public static function register_menu(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		add_submenu_page(
			'mwp_manager',
			__( 'Profile Templates', 'mikrotik-user-manager-bridge' ),
			__( 'Profiles', 'mikrotik-user-manager-bridge' ),
			'manage_options',
			'mumb_profile_settings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['mumb_profile_settings_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['mumb_profile_settings_nonce'] ), 'mumb_profile_settings' ) ) {
			$template = SettingsService::get_profile_template();
			if ( isset( $_FILES['template_file'] ) && is_array( $_FILES['template_file'] ) && ! empty( $_FILES['template_file']['tmp_name'] ) ) {
				$tmp_name = $_FILES['template_file']['tmp_name'];
				if ( is_uploaded_file( $tmp_name ) ) {
					$raw = file_get_contents( $tmp_name );
					if ( false !== $raw ) {
						$template = MUMB_Profile_Service::sanitize_template( $raw );
					}
				}
			}

			if ( isset( $_POST['template_content'] ) ) {
				$template = MUMB_Profile_Service::sanitize_template( wp_unslash( (string) $_POST['template_content'] ) );
			}
			SettingsService::set_profile_template( $template );

			$mode = isset( $_POST['profile_mode'] ) ? sanitize_text_field( (string) $_POST['profile_mode'] ) : MUMB_Profile_Service::MODE_TEMPLATE;
			SettingsService::set_profile_mode( $mode );

			$server_id = isset( $_POST['profile_default_server'] ) ? (int) $_POST['profile_default_server'] : 0;
			SettingsService::set_profile_default_server( $server_id );

			$ttl_minutes = isset( $_POST['profile_token_ttl'] ) ? max( 1, (int) $_POST['profile_token_ttl'] ) : 5;
			SettingsService::set_profile_token_ttl( $ttl_minutes * MINUTE_IN_SECONDS );

			add_settings_error( 'mumb_profile_settings', 'mumb_profile_saved', __( 'Profile settings saved.', 'mikrotik-user-manager-bridge' ), 'updated' );
			wp_safe_redirect( admin_url( 'admin.php?page=mumb_profile_settings' ) );
			exit;
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mikrotik-user-manager-bridge' ) );
		}
		$template      = SettingsService::get_profile_template();
		$mode          = SettingsService::get_profile_mode();
		$default       = SettingsService::get_profile_default_server();
		$ttl_seconds   = SettingsService::get_profile_token_ttl();
		$ttl_minutes   = max( 1, (int) ceil( $ttl_seconds / MINUTE_IN_SECONDS ) );
		$servers       = MUMB_Server_Registry::get_servers();

		settings_errors( 'mumb_profile_settings' );
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Profile Downloads', 'mikrotik-user-manager-bridge' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Configure the OpenVPN profile template and defaults used for user downloads.', 'mikrotik-user-manager-bridge' ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'mumb_profile_settings', 'mumb_profile_settings_nonce' );

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Mode', 'mikrotik-user-manager-bridge' ) . '</th><td>';
		echo '<fieldset>';
		echo '<label><input type="radio" name="profile_mode" value="template" ' . checked( $mode, MUMB_Profile_Service::MODE_TEMPLATE, false ) . ' /> ' . esc_html__( 'Static template', 'mikrotik-user-manager-bridge' ) . '</label><br />';
		echo '<label><input type="radio" name="profile_mode" value="router" ' . checked( $mode, MUMB_Profile_Service::MODE_ROUTER, false ) . ' /> ' . esc_html__( 'Fetch from router REST API', 'mikrotik-user-manager-bridge' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Router mode requires the MikroTik User Manager REST API to support exporting profiles.', 'mikrotik-user-manager-bridge' ) . '</p>';
		echo '</fieldset>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Template file', 'mikrotik-user-manager-bridge' ) . '</th><td>';
		echo '<input type="file" name="template_file" accept=".ovpn,.conf,.txt" />';
		echo '<p class="description">' . esc_html__( 'Upload a base .ovpn file to replace the current template. Uploading is optional if you prefer editing inline.', 'mikrotik-user-manager-bridge' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Template editor', 'mikrotik-user-manager-bridge' ) . '</th><td>';
		echo '<textarea name="template_content" rows="12" class="large-text code" spellcheck="false">' . esc_textarea( $template ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Available placeholders: <SERVER_IP>, <USERNAME>, <PASSWORD>.', 'mikrotik-user-manager-bridge' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Default server', 'mikrotik-user-manager-bridge' ) . '</th><td>';
		echo '<select name="profile_default_server">';
		echo '<option value="0">' . esc_html__( '— Select —', 'mikrotik-user-manager-bridge' ) . '</option>';
		foreach ( $servers as $server ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $server['id'],
				selected( $default, (int) $server['id'], false ),
				esc_html( sprintf( '%s (%s)', $server['name'], $server['hostname'] ) )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The default server is used for template placeholders and router fetch mode.', 'mikrotik-user-manager-bridge' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Token expiry (minutes)', 'mikrotik-user-manager-bridge' ) . '</th><td>';
		echo '<input type="number" name="profile_token_ttl" min="1" value="' . esc_attr( $ttl_minutes ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Download tokens are single-use and expire after this many minutes.', 'mikrotik-user-manager-bridge' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';

		submit_button( __( 'Save Changes', 'mikrotik-user-manager-bridge' ) );
		echo '</form>';

		echo '</div>';
	}
}
