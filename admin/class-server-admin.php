<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUMB_Server_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_mumb_refresh_servers', array( __CLASS__, 'ajax_refresh_servers' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );
	}

	public static function register_menu(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		add_submenu_page(
			'mwp_manager',
			__( 'Server Registry', 'mikrotik-user-manager-bridge' ),
			__( 'Servers', 'mikrotik-user-manager-bridge' ),
			'manage_options',
			'mumb_servers',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['mumb_server_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['mumb_server_nonce'] ), 'mumb_save_server' ) ) {
			$server_id = isset( $_POST['server_id'] ) ? (int) $_POST['server_id'] : 0;
			$data      = array(
				'id'        => $server_id,
				'name'      => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'hostname'  => sanitize_text_field( wp_unslash( $_POST['hostname'] ?? '' ) ),
				'role'      => sanitize_text_field( wp_unslash( $_POST['role'] ?? MUMB_Server_Registry::ROLE_USERMANAGER ) ),
				'api_port'  => isset( $_POST['api_port'] ) ? (int) wp_unslash( $_POST['api_port'] ) : 8728,
				'rest_port' => isset( $_POST['rest_port'] ) ? (int) wp_unslash( $_POST['rest_port'] ) : 443,
				'notes'     => wp_kses_post( wp_unslash( $_POST['notes'] ?? '' ) ),
			);
			if ( '' === $data['name'] || '' === $data['hostname'] ) {
				self::store_notice( 'mumb_servers_required', __( 'Name and hostname are required.', 'mikrotik-user-manager-bridge' ), 'error' );
				return;
			}
			MUMB_Server_Registry::upsert( $data );
			self::store_notice( 'mumb_servers_saved', __( 'Server saved.', 'mikrotik-user-manager-bridge' ), 'updated' );
			wp_safe_redirect( admin_url( 'admin.php?page=mumb_servers' ) );
			exit;
		}

		if ( isset( $_GET['mumb_server_delete'], $_GET['_wpnonce'] ) && wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'mumb_delete_server' ) ) {
			MUMB_Server_Registry::delete( (int) $_GET['mumb_server_delete'] );
			self::store_notice( 'mumb_servers_deleted', __( 'Server deleted.', 'mikrotik-user-manager-bridge' ), 'updated' );
			wp_safe_redirect( admin_url( 'admin.php?page=mumb_servers' ) );
			exit;
		}

		if ( isset( $_GET['mumb_server_refresh'], $_GET['_wpnonce'] ) && wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'mumb_refresh_server' ) ) {
			MUMB_Mikrotik_Health::refresh_server( (int) $_GET['mumb_server_refresh'] );
			self::store_notice( 'mumb_servers_refreshed', __( 'Health check triggered.', 'mikrotik-user-manager-bridge' ), 'updated' );
			wp_safe_redirect( admin_url( 'admin.php?page=mumb_servers' ) );
			exit;
		}

		if ( isset( $_GET['mumb_server_test'], $_GET['_wpnonce'] ) && wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'mumb_test_server' ) ) {
			$result  = MUMB_Mikrotik_Health::refresh_server( (int) $_GET['mumb_server_test'] );
			$latency = $result && null !== $result['latency_ms'] ? (string) $result['latency_ms'] : '—';
			$status  = $result['status'] ?? MUMB_Server_Registry::STATUS_OFFLINE;
			self::store_notice(
				'mumb_servers_tested',
				sprintf(
					__( 'Test complete — status: %1$s, latency: %2$s ms.', 'mikrotik-user-manager-bridge' ),
					esc_html( $status ),
					esc_html( $latency )
				),
				'updated'
			);
			wp_safe_redirect( admin_url( 'admin.php?page=mumb_servers' ) );
			exit;
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mikrotik-user-manager-bridge' ) );
		}
		self::output_notices();
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Server Registry', 'mikrotik-user-manager-bridge' ) . '</h1>';
		echo '<style>.mumb-status{display:inline-flex;align-items:center;justify-content:center;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;text-transform:capitalize;background:#e2e8f0;color:#1f2937}.mumb-status-success{background:#dcfce7;color:#166534}.mumb-status-warning{background:#fef3c7;color:#92400e}.mumb-status-error{background:#fee2e2;color:#991b1b}</style>';
		$action = sanitize_key( $_GET['action'] ?? '' );
		if ( 'edit' === $action ) {
			self::render_form();
		} else {
			self::render_table();
		}
		echo '</div>';
	}

	private static function render_form(): void {
		$server_id = isset( $_GET['server_id'] ) ? (int) $_GET['server_id'] : 0;
		$server    = $server_id ? MUMB_Server_Registry::get_server( $server_id ) : null;
		$defaults  = array(
			'id'        => 0,
			'name'      => '',
			'hostname'  => '',
			'role'      => MUMB_Server_Registry::ROLE_USERMANAGER,
			'api_port'  => 8728,
			'rest_port' => 443,
			'notes'     => '',
		);
		$server = wp_parse_args( $server ?? array(), $defaults );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=mumb_servers' ) ) . '">';
		wp_nonce_field( 'mumb_save_server', 'mumb_server_nonce' );
		echo '<input type="hidden" name="server_id" value="' . esc_attr( $server['id'] ) . '" />';
		echo '<table class="form-table" role="presentation">';
		self::render_text_row( 'name', __( 'Name', 'mikrotik-user-manager-bridge' ), $server['name'], true );
		self::render_text_row( 'hostname', __( 'Hostname / IP', 'mikrotik-user-manager-bridge' ), $server['hostname'], true );
		self::render_select_row(
			'role',
			__( 'Role', 'mikrotik-user-manager-bridge' ),
			array(
				MUMB_Server_Registry::ROLE_USERMANAGER => __( 'User Manager', 'mikrotik-user-manager-bridge' ),
				MUMB_Server_Registry::ROLE_VPN         => __( 'VPN Node', 'mikrotik-user-manager-bridge' ),
			),
			$server['role']
		);
		self::render_number_row( 'api_port', __( 'API Port', 'mikrotik-user-manager-bridge' ), $server['api_port'], 1, 65535 );
		self::render_number_row( 'rest_port', __( 'REST Port', 'mikrotik-user-manager-bridge' ), $server['rest_port'], 1, 65535 );
		self::render_textarea_row( 'notes', __( 'Notes', 'mikrotik-user-manager-bridge' ), $server['notes'] );
		echo '</table>';
		submit_button( $server_id ? __( 'Update Server', 'mikrotik-user-manager-bridge' ) : __( 'Add Server', 'mikrotik-user-manager-bridge' ) );
		echo ' <a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=mumb_servers' ) ) . '">' . esc_html__( 'Cancel', 'mikrotik-user-manager-bridge' ) . '</a>';
		echo '</form>';
	}

	private static function render_table(): void {
		$role_filter   = sanitize_key( $_GET['filter_role'] ?? '' );
		$status_filter = sanitize_key( $_GET['filter_status'] ?? '' );
		$orderby       = sanitize_key( $_GET['orderby'] ?? 'name' );
		$order         = strtoupper( sanitize_text_field( $_GET['order'] ?? 'ASC' ) );
		$args          = array(
			'role'    => $role_filter ?: null,
			'status'  => $status_filter ?: null,
			'orderby' => $orderby,
			'order'   => $order,
		);
		$servers = MUMB_Server_Registry::get_servers( $args );
		$base_url = admin_url( 'admin.php?page=mumb_servers' );

		echo '<div class="mwp-toolbar" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
		echo '<a class="button button-primary" href="' . esc_url( add_query_arg( array( 'action' => 'edit' ), $base_url ) ) . '">' . esc_html__( 'Add Server', 'mikrotik-user-manager-bridge' ) . '</a>';
		echo '<button type="button" class="button" id="mumb-refresh-all" data-nonce="' . esc_attr( wp_create_nonce( 'mumb_refresh_servers' ) ) . '">' . esc_html__( 'Refresh Status', 'mikrotik-user-manager-bridge' ) . '</button>';
		echo '<form method="get" style="margin-left:auto;display:flex;gap:8px;align-items:center;">';
		echo '<input type="hidden" name="page" value="mumb_servers" />';
		echo '<select name="filter_role">';
		echo '<option value="">' . esc_html__( 'All roles', 'mikrotik-user-manager-bridge' ) . '</option>';
		foreach (
            array(
				MUMB_Server_Registry::ROLE_USERMANAGER => __( 'User Manager', 'mikrotik-user-manager-bridge' ),
				MUMB_Server_Registry::ROLE_VPN         => __( 'VPN Node', 'mikrotik-user-manager-bridge' ),
            ) as $key => $label 
        ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( $role_filter, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<select name="filter_status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'mikrotik-user-manager-bridge' ) . '</option>';
		foreach (
            array(
				MUMB_Server_Registry::STATUS_ONLINE   => __( 'Online', 'mikrotik-user-manager-bridge' ),
				MUMB_Server_Registry::STATUS_DEGRADED => __( 'Degraded', 'mikrotik-user-manager-bridge' ),
				MUMB_Server_Registry::STATUS_OFFLINE  => __( 'Offline', 'mikrotik-user-manager-bridge' ),
            ) as $key => $label 
        ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( $status_filter, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'mikrotik-user-manager-bridge' ), 'secondary', '', false );
		echo '</form>';
		echo '</div>';

		if ( empty( $servers ) ) {
			echo '<p>' . esc_html__( 'No servers registered yet.', 'mikrotik-user-manager-bridge' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		self::render_header_cell( 'name', __( 'Name', 'mikrotik-user-manager-bridge' ), $orderby, $order );
		self::render_header_cell( 'hostname', __( 'Hostname', 'mikrotik-user-manager-bridge' ), $orderby, $order );
		echo '<th>' . esc_html__( 'Role', 'mikrotik-user-manager-bridge' ) . '</th>';
		self::render_header_cell( 'status', __( 'Status', 'mikrotik-user-manager-bridge' ), $orderby, $order );
		self::render_header_cell( 'last_ping', __( 'Last Checked', 'mikrotik-user-manager-bridge' ), $orderby, $order );
		self::render_header_cell( 'latency_ms', __( 'Latency (ms)', 'mikrotik-user-manager-bridge' ), $orderby, $order );
		self::render_header_cell( 'active_users', __( 'Active Users', 'mikrotik-user-manager-bridge' ), $orderby, $order );
		echo '<th>' . esc_html__( 'Profiles', 'mikrotik-user-manager-bridge' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mikrotik-user-manager-bridge' ) . '</th>';
		echo '</tr></thead><tbody>';

		$roles = array(
			MUMB_Server_Registry::ROLE_USERMANAGER => __( 'User Manager', 'mikrotik-user-manager-bridge' ),
			MUMB_Server_Registry::ROLE_VPN         => __( 'VPN Node', 'mikrotik-user-manager-bridge' ),
		);

		foreach ( $servers as $server ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $server['name'] ) . '</strong></td>';
			echo '<td>' . esc_html( $server['hostname'] ) . '</td>';
			echo '<td>' . esc_html( $roles[ $server['role'] ] ?? $server['role'] ) . '</td>';
			echo '<td>' . self::render_status_badge( $server['status'] ) . '</td>';
			$timestamp = $server['last_ping'] ? strtotime( $server['last_ping'] . ' UTC' ) : false;
			$display_time = $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '—';
			echo '<td>' . esc_html( $display_time ) . '</td>';
			echo '<td>' . esc_html( null === $server['latency_ms'] ? '—' : (string) $server['latency_ms'] ) . '</td>';
			echo '<td>' . esc_html( null === $server['active_users'] ? '—' : (string) $server['active_users'] ) . '</td>';
			echo '<td>' . esc_html( self::profiles_column_text( $server ) ) . '</td>';
			echo '<td>' . self::action_links( $server ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function render_header_cell( string $column, string $label, string $current, string $order ): void {
		$next_order = ( $current === $column && 'ASC' === strtoupper( $order ) ) ? 'DESC' : 'ASC';
		$url        = add_query_arg(
			array(
				'orderby' => $column,
				'order'   => $next_order,
			)
		);
		$classes = 'manage-column sortable';
		if ( $current === $column ) {
			$classes .= ' sorted ' . strtolower( $order );
		}
		echo '<th scope="col" class="' . esc_attr( $classes ) . '"><a href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span><span class="sorting-indicator"></span></a></th>';
	}

	private static function render_status_badge( string $status ): string {
		$map = array(
			MUMB_Server_Registry::STATUS_ONLINE   => 'mumb-status-success',
			MUMB_Server_Registry::STATUS_DEGRADED => 'mumb-status-warning',
			MUMB_Server_Registry::STATUS_OFFLINE  => 'mumb-status-error',
		);
		$class = $map[ $status ] ?? 'mumb-status-warning';
		return '<span class="mumb-status ' . esc_attr( $class ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
	}

	private static function action_links( array $server ): string {
		$base   = admin_url( 'admin.php?page=mumb_servers' );
		$edit   = add_query_arg( array( 'action' => 'edit', 'server_id' => $server['id'] ), $base );
		$refresh = wp_nonce_url( add_query_arg( 'mumb_server_refresh', $server['id'], $base ), 'mumb_refresh_server' );
		$test   = wp_nonce_url( add_query_arg( 'mumb_server_test', $server['id'], $base ), 'mumb_test_server' );
		$delete = wp_nonce_url( add_query_arg( 'mumb_server_delete', $server['id'], $base ), 'mumb_delete_server' );
		$links  = array(
			'<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'mikrotik-user-manager-bridge' ) . '</a>',
			'<a href="' . esc_url( $refresh ) . '">' . esc_html__( 'Refresh', 'mikrotik-user-manager-bridge' ) . '</a>',
			'<a href="' . esc_url( $test ) . '">' . esc_html__( 'Test', 'mikrotik-user-manager-bridge' ) . '</a>',
			'<a class="delete" href="' . esc_url( $delete ) . '" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this server?', 'mikrotik-user-manager-bridge' ) ) . '\');">' . esc_html__( 'Delete', 'mikrotik-user-manager-bridge' ) . '</a>',
		);
		return implode( ' | ', $links );
	}

	private static function render_text_row( string $name, string $label, string $value, bool $required = false ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required' : '' ) . ' />';
		echo '</td></tr>';
	}

	private static function render_number_row( string $name, string $label, int $value, int $min, int $max ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '" />';
		echo '</td></tr>';
	}

	private static function render_select_row( string $name, string $label, array $options, string $value ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $text ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( $value, $key, false ), esc_html( $text ) );
		}
		echo '</select>';
		echo '</td></tr>';
	}

	private static function render_textarea_row( string $name, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="4" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
		echo '</td></tr>';
	}

	private static function store_notice( string $code, string $message, string $type ): void {
		$bucket = get_transient( 'mumb_servers_notices' );
		if ( ! is_array( $bucket ) ) {
			$bucket = array();
		}
		$bucket[] = array(
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
		);
		set_transient( 'mumb_servers_notices', $bucket, MINUTE_IN_SECONDS );
	}

	private static function output_notices(): void {
		$bucket = get_transient( 'mumb_servers_notices' );
		if ( is_array( $bucket ) ) {
			delete_transient( 'mumb_servers_notices' );
			foreach ( $bucket as $notice ) {
				add_settings_error( 'mumb_servers', $notice['code'], $notice['message'], $notice['type'] );
			}
		}
		settings_errors( 'mumb_servers' );
	}

	private static function profiles_column_text( array $server ): string {
		if ( ! class_exists( 'MUMB_Profile_Service' ) ) {
			return '—';
		}
		$mode = \MUMB\Services\SettingsService::get_profile_mode();
		if ( MUMB_Profile_Service::MODE_ROUTER === $mode ) {
			return __( 'Yes (router)', 'mikrotik-user-manager-bridge' );
		}
		if ( ! MUMB_Profile_Service::server_supports_downloads( $server ) ) {
			return '—';
		}
		$default = MUMB_Profile_Service::get_default_server_id();
		if ( $default > 0 && (int) ( $server['id'] ?? 0 ) === $default ) {
			return __( 'Yes (default)', 'mikrotik-user-manager-bridge' );
		}
		return __( 'Yes', 'mikrotik-user-manager-bridge' );
	}

	public static function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'page_mumb_servers' ) === false ) {
			return;
		}
		add_action( 'admin_footer', array( __CLASS__, 'print_inline_admin_assets' ) );
	}

	public static function print_inline_admin_assets(): void {
		$script = self::inline_script();
		$style  = '.mumb-server-refresh{margin-top:8px}.mumb-server-dashboard{margin:0;padding-left:18px}.mumb-server-dashboard li{margin-bottom:6px;}';
		echo '<style>' . $style . '</style>';
		echo '<script>' . $script . '</script>';
	}

	private static function inline_script(): string {
		$ajax_url = admin_url( 'admin-ajax.php' );
		$success  = esc_js( __( 'Server status refreshed.', 'mikrotik-user-manager-bridge' ) );
		$error   = esc_js( __( 'Failed to refresh server status.', 'mikrotik-user-manager-bridge' ) );
		return <<<JS
(function(){
	var button = document.getElementById('mumb-refresh-all');
	if(!button){return;}
	var noticeArea = document.createElement('div');
	noticeArea.className = 'mumb-server-refresh';
	button.parentNode.insertBefore(noticeArea, button.nextSibling);
	button.addEventListener('click', function(){
		if(button.dataset.loading){return;}
		button.dataset.loading = '1';
		button.classList.add('updating-message');
		var form = new FormData();
		form.append('action','mumb_refresh_servers');
		form.append('_wpnonce',button.getAttribute('data-nonce')||'');
		fetch('{$ajax_url}', {method:'POST',credentials:'same-origin',body:form}).then(function(resp){
			if(!resp.ok){throw new Error('HTTP '+resp.status);}
			return resp.json();
		}).then(function(payload){
			noticeArea.innerHTML = '<div class="notice notice-success"><p>{$success}</p></div>';
			setTimeout(function(){ location.reload(); }, 500);
		}).catch(function(){
			noticeArea.innerHTML = '<div class="notice notice-error"><p>{$error}</p></div>';
		}).finally(function(){
			button.classList.remove('updating-message');
			button.dataset.loading = '';
		});
	});
})();
JS;
	}

	public static function ajax_refresh_servers(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'mumb_refresh_servers', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mikrotik-user-manager-bridge' ) ), 403 );
		}
		$servers = MUMB_Server_Registry::get_servers();
		foreach ( $servers as $server ) {
			MUMB_Mikrotik_Health::refresh_server( (int) $server['id'] );
		}
		wp_send_json_success( array( 'refreshed' => count( $servers ) ) );
	}

	public static function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'mumb_server_status_widget', __( 'MikroTik Servers', 'mikrotik-user-manager-bridge' ), array( __CLASS__, 'render_dashboard_widget' ) );
	}

	public static function render_dashboard_widget(): void {
		$servers = MUMB_Server_Registry::get_servers();
		if ( empty( $servers ) ) {
			echo '<p>' . esc_html__( 'No servers registered yet.', 'mikrotik-user-manager-bridge' ) . '</p>';
			return;
		}
		echo '<ul class="mumb-server-dashboard">';
		foreach ( $servers as $server ) {
			$status_badge = self::render_status_badge( $server['status'] ?? MUMB_Server_Registry::STATUS_OFFLINE );
			$latency      = isset( $server['latency_ms'] ) && null !== $server['latency_ms'] ? $server['latency_ms'] . ' ms' : '—';
			echo '<li>'; 
			echo '<strong>' . esc_html( $server['name'] ?? $server['hostname'] ) . '</strong> ' . $status_badge;
			echo '<br /><span>' . esc_html__( 'Latency:', 'mikrotik-user-manager-bridge' ) . ' ' . esc_html( $latency ) . '</span>';
			echo '</li>';
		}
		echo '</ul>';
	}
}
