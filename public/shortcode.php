<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use MUMB\Services\SettingsService;

add_shortcode( 'mikrotik_dashboard', function () {
    $brand = MUMB_Branding::name();
    $logo  = MUMB_Branding::logo_url();

    if ( ! is_user_logged_in() ) {
        return '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Please log in to view your %s dashboard.', 'mikrotik-user-manager-bridge'), $brand ) ) . '</p></div>';
    }

    $user      = wp_get_current_user();
    $username  = $user->user_login;
    $profile   = '';
    $group     = '';
    $comment   = '';
    $updated   = null;

    global $wpdb;
    /** @var string $table_prefix */
    $table_prefix = $wpdb->prefix;
    /** @var mixed $row */
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT profile, comment, updated_at FROM %i WHERE username=%s LIMIT 1', $table_prefix . 'mikrotik_users', $username ), ARRAY_A );
    if ( is_array( $row ) ) {
        $profile = isset( $row['profile'] ) ? (string) $row['profile'] : '';
        $comment = isset( $row['comment'] ) ? (string) $row['comment'] : '';
        $updated = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null;
    }

    // Fall back to synced user meta when available (mirrored via MUMB_WP_Users).
    /** @var mixed $meta_group */
    $meta_group = get_user_meta( (int) $user->ID, 'mumb_group', true );
    if ( is_string( $meta_group ) && $meta_group !== '' ) {
        $group = $meta_group;
    }
    /** @var mixed $meta_profile */
    $meta_profile = get_user_meta( (int) $user->ID, 'mumb_profile', true );
    if ( $profile === '' && is_string( $meta_profile ) ) {
        $profile = $meta_profile;
    }
    if ( $group === '' && $profile !== '' ) {
        $group = $profile;
    }

    /** @var mixed $groups */
    $groups = $wpdb->get_col( $wpdb->prepare( 'SELECT name FROM %i ORDER BY name ASC', $table_prefix . 'mikrotik_groups' ) );
    if ( ! is_array( $groups ) ) {
        $groups = [];
    }

    $sessions = [];
    try {
        $settings = MUMB_Settings::load();
        if ( $settings->is_configured() && class_exists( 'MikroTik_API' ) ) {
            $api = new MikroTik_API([
                'host'        => $settings->host,
                'username'    => $settings->username,
                'password'    => $settings->decrypted_password(),
                'port'        => $settings->port,
                'ssl'         => $settings->ssl,
                'api_timeout' => $settings->api_timeout,
            ]);
            $res = $api->get_active_sessions();
            if ( is_array( $res ) && $res['status'] === 'ok' ) {
                $data = $res['data'] ?? [];
                foreach ( $data as $session ) {
                    if ( ! is_array( $session ) ) {
                        continue;
                    }
                    $name = isset( $session['name'] ) && is_scalar( $session['name'] ) ? strtolower( (string) $session['name'] ) : '';
                    if ( $name === strtolower( $username ) ) {
                        $sessions[] = $session;
                    }
                }
            }
        }
    } catch ( \Throwable $ignored ) {
        // Do not surface API issues on front-end dashboard.
    }

    static $printed_style = false;
    $rest_base  = esc_url_raw( rest_url( 'mwp/v1' ) );
    $rest_nonce = wp_create_nonce( 'wp_rest' );

    ob_start();
    if ( ! $printed_style ) {
        echo '<style>.mvb-user-dashboard{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:20px;box-shadow:0 1px 2px rgb(15 23 42 / 6%);} .mvb-branding{display:flex;align-items:center;gap:12px;margin-bottom:8px;} .mvb-branding-logo{max-height:48px;width:auto;} .mvb-branding-name{margin:0;font-size:1.5rem;font-weight:600;color:#0f172a;} .mvb-branding-tagline{margin:0 0 16px;color:#475569;} .mvb-location-form{margin:16px 0;} .mvb-location-field{display:flex;gap:8px;align-items:center;} @media (max-width:640px){.mvb-location-field{flex-direction:column;align-items:stretch;} .mvb-location-field select{width:100%;}}</style>';
        $printed_style = true;
    }

    echo '<div class="mvb-user-dashboard">';
    echo '<div class="mvb-branding">';
    if ( $logo ) {
        echo '<img class="mvb-branding-logo" src="' . esc_url( $logo ) . '" alt="' . esc_attr( $brand ) . '" />';
    }
    echo '<div class="mvb-branding-text">';
    echo '<h2 class="mvb-branding-name">' . esc_html( sprintf( __( '%s Dashboard', 'mikrotik-user-manager-bridge'), $brand ) ) . '</h2>';
    echo '<p class="mvb-branding-tagline">' . esc_html__( 'VPN Dashboard', 'mikrotik-user-manager-bridge') . '</p>';
    echo '</div>';
    echo '</div>';

    echo '<p><strong>' . esc_html__( 'Username', 'mikrotik-user-manager-bridge') . ':</strong> ' . esc_html( $username ) . '</p>';
    echo '<p><strong>' . esc_html__( 'Profile', 'mikrotik-user-manager-bridge') . ':</strong> ' . esc_html( $profile !== '' ? $profile : __( 'Not set', 'mikrotik-user-manager-bridge') ) . '</p>';
    echo '<p><strong>' . esc_html__( 'Group', 'mikrotik-user-manager-bridge') . ':</strong> ' . esc_html( $group !== '' ? $group : __( 'Not set', 'mikrotik-user-manager-bridge') ) . '</p>';
    if ( $comment !== '' ) {
        echo '<p><strong>' . esc_html__( 'Comment', 'mikrotik-user-manager-bridge') . ':</strong> ' . esc_html( $comment ) . '</p>';
    }

    if ( ! empty( $groups ) ) {
        echo '<h3>' . esc_html__( 'Change Location', 'mikrotik-user-manager-bridge') . '</h3>';
        echo '<div id="mwp-group-result"></div>';
        echo '<form class="mvb-location-form" id="mwp-group-form">';
        echo '<div class="mvb-location-field">';
        echo '<label class="screen-reader-text" for="mwp-group-select">' . esc_html__( 'Select group', 'mikrotik-user-manager-bridge') . '</label>';
        echo '<select id="mwp-group-select">';
        echo '<option value="">' . esc_html__( 'Select location…', 'mikrotik-user-manager-bridge') . '</option>';
        /** @var mixed $group_option */
        foreach ( $groups as $group_option ) {
            $selected = selected( $group, $group_option, false );
            $group_str = is_string( $group_option ) ? $group_option : '';
            echo '<option value="' . esc_attr( $group_str ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $group_str ) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" class="button" id="mwp-group-btn">' . esc_html__( 'Update', 'mikrotik-user-manager-bridge') . '</button>';
        echo '</div>';
        echo '</form>';
    }

    echo '<h3>' . esc_html__( 'Active Sessions', 'mikrotik-user-manager-bridge') . '</h3>';
    if ( empty( $sessions ) ) {
        echo '<p>' . esc_html__( 'No active sessions.', 'mikrotik-user-manager-bridge') . '</p>';
    } else {
        echo '<ul>';
        foreach ( $sessions as $session ) {
            $addr = isset( $session['address'] ) && is_scalar( $session['address'] ) ? (string) $session['address'] : '';
            $upt  = isset( $session['uptime'] ) && is_scalar( $session['uptime'] ) ? (string) $session['uptime'] : '';
            echo '<li>' . esc_html( $addr . ' — ' . $upt ) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';

    if ( ! empty( $groups ) ) {
        $messages = [
            'missing' => __( 'Please choose a location first.', 'mikrotik-user-manager-bridge'),
            'success' => __( 'Location update queued. It may take a moment to apply.', 'mikrotik-user-manager-bridge'),
            'error'   => __( 'Request failed. Please try again.', 'mikrotik-user-manager-bridge'),
        ];
        printf(
            <<<'JS'
<script>
(function(){
    const base = %1$s;
    const nonce = %2$s;
    const msgs = %3$s;
    const form = document.getElementById("mwp-group-form");
    const select = document.getElementById("mwp-group-select");
    const result = document.getElementById("mwp-group-result");
    if (!form || !select || !result) { return; }
    function show(type, message) {
        const cls = type === "success" ? "notice notice-success" : type === "warning" ? "notice notice-warning" : "notice notice-error";
        result.innerHTML = '<div class="' + cls + '"><p>' + message + '</p></div>';
    }
    form.addEventListener("submit", function(evt){
        evt.preventDefault();
        if (!select.value) {
            show("warning", msgs.missing);
            return;
        }
        fetch(base + '/self/group', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify({ group: select.value })
        }).then(function(resp){
            return resp.json().then(function(body){ return { ok: resp.ok, body: body }; });
        }).then(function(payload){
            if (payload.ok && payload.body && payload.body.success) {
                show('success', msgs.success);
            } else {
                var msg = (payload.body && (payload.body.message || payload.body.error)) || msgs.error;
                show('error', msg);
            }
        }).catch(function(){
            show('error', msgs.error);
});

add_shortcode( 'mikrotik_profile_download', function ( $atts ) {
	if ( ! is_user_logged_in() ) {
		return '<div class="notice notice-warning"><p>' . esc_html__( 'Please log in to download your VPN profile.', 'mikrotik-user-manager-bridge' ) . '</p></div>';
	}
	if ( ! class_exists( 'MUMB_Profile_Service' ) ) {
		return '<div class="notice notice-error"><p>' . esc_html__( 'Profile downloads are not available.', 'mikrotik-user-manager-bridge' ) . '</p></div>';
	}
	$atts = shortcode_atts(
		array(
			'server' => 0,
			'label'  => __( 'Download VPN Profile', 'mikrotik-user-manager-bridge' ),
		),
		$atts,
		'mikrotik_profile_download'
	);
	$user_id   = get_current_user_id();
	$server_id = (int) $atts['server'];
	if ( $server_id <= 0 ) {
		$server_id = MUMB_Profile_Service::get_default_server_id();
	}
	if ( $server_id <= 0 ) {
		return '<div class="notice notice-error"><p>' . esc_html__( 'Profile downloads are not configured yet.', 'mikrotik-user-manager-bridge' ) . '</p></div>';
	}
	$token = MUMB_Profile_Service::issue_download_token( $user_id, array( 'server_id' => $server_id ) );
	if ( is_wp_error( $token ) ) {
		return '<div class="notice notice-error"><p>' . esc_html( $token->get_error_message() ) . '</p></div>';
	}
	$download_url = add_query_arg(
		array(
			'token'     => $token,
			'server_id' => $server_id,
		),
		rest_url( 'mikrotik/v1/profile/download' )
	);
	$download_url = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), $download_url );
	$ttl_seconds = SettingsService::get_profile_token_ttl();
	$ttl_minutes = max( 1, (int) ceil( $ttl_seconds / MINUTE_IN_SECONDS ) );
	$template    = apply_filters( 'mumb_profile_download_template', MUMB_DIR . 'templates/profile-download.php' );
	$label       = (string) apply_filters( 'mumb_profile_download_button_label', $atts['label'] );

	ob_start();
	if ( $template && file_exists( $template ) ) {
		$download_url_safe = esc_url( $download_url );
		$label_safe        = esc_html( $label );
		$ttl_display       = (int) $ttl_minutes;
		include $template;
	} else {
		echo '<a class="button" href="' . esc_url( $download_url ) . '">' . esc_html( $label ) . '</a>';
	}
	return ob_get_clean();
} );

add_shortcode( 'mikrotik_server_status', function () {
	static $script_printed = false;
	$endpoint = esc_url( rest_url( 'mikrotik/v1/servers/status' ) );
	$template = apply_filters( 'mumb_server_status_template', MUMB_DIR . 'templates/server-status.php' );
	$container_id = 'mumb-server-status-' . wp_rand( 1000, 9999 );

	ob_start();
	if ( $template && file_exists( $template ) ) {
		$endpoint_safe  = $endpoint;
		$container_safe = esc_attr( $container_id );
		include $template;
	} else {
		echo '<div id="' . esc_attr( $container_id ) . '" class="mumb-server-status"></div>';
	}

	if ( ! $script_printed ) {
		$script_printed = true;
		?>
		<script>
		(function(){
			function renderServerStatus(container, data){
				if(!container){return;}
				if(!Array.isArray(data) || data.length === 0){
					container.innerHTML = '<p><?php echo esc_js( __( 'No server information available.', 'mikrotik-user-manager-bridge' ) ); ?></p>';
					return;
				}
				var html = '<div class="mumb-server-grid">';
				data.forEach(function(row){
					var status = String(row.status||'offline').toLowerCase();
					var badgeClass = 'mumb-server-badge mumb-server-badge-' + status;
					var name = row.name || row.hostname || '—';
					var latency = typeof row.latency_ms === 'number' ? row.latency_ms + ' ms' : '—';
					var active = typeof row.active_users === 'number' ? row.active_users : '—';
					var icon = status === 'online' ? '✅' : status === 'degraded' ? '⚠️' : '❌';
					html += '<div class="mumb-server-card">';
					html += '<div class="mumb-server-card__head"><span class="mumb-server-name">' + window._e_esc(name) + '</span>';
					html += '<span class="' + badgeClass + '">' + icon + ' ' + window._e_esc(status.charAt(0).toUpperCase() + status.slice(1)) + '</span></div>';
					html += '<div class="mumb-server-meta"><span><?php echo esc_js( __( 'Latency', 'mikrotik-user-manager-bridge' ) ); ?>: ' + window._e_esc(latency) + '</span>';
					html += '<span><?php echo esc_js( __( 'Active users', 'mikrotik-user-manager-bridge' ) ); ?>: ' + window._e_esc(String(active)) + '</span></div>';
					html += '</div>';
				});
				html += '</div>';
				container.innerHTML = html;
			}
			function fetchStatus(container){
				var endpoint = container ? container.getAttribute('data-endpoint') : '';
				if(!endpoint){ return; }
				container.innerHTML = '<p class="mumb-server-loading"><?php echo esc_js( __( 'Loading server status…', 'mikrotik-user-manager-bridge' ) ); ?></p>';
				fetch(endpoint, { credentials: 'omit' }).then(function(resp){
					if(!resp.ok){ throw new Error('HTTP ' + resp.status); }
					return resp.json();
				}).then(function(payload){
					var servers = (payload && payload.servers) ? payload.servers : payload;
					renderServerStatus(container, Array.isArray(servers) ? servers : []);
				}).catch(function(){
					container.innerHTML = '<p class="mumb-server-error"><?php echo esc_js( __( 'Unable to load server status.', 'mikrotik-user-manager-bridge' ) ); ?></p>';
				});
			}
			window.addEventListener('DOMContentLoaded', function(){
				if (!window._e_esc){
					window._e_esc = function(str){
						return String(str || '').replace(/[&<>"']/g,function(s){
							return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[s]);
						});
					};
				}
				document.querySelectorAll('.mumb-server-status').forEach(fetchStatus);
			});
		})();
		</script>
		<style>
		.mumb-server-grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));}
		.mumb-server-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 2px rgba(15,23,42,0.05);display:flex;flex-direction:column;gap:12px;}
		.mumb-server-card__head{display:flex;justify-content:space-between;align-items:center;gap:12px;}
		.mumb-server-name{font-weight:600;font-size:1rem;color:#0f172a;}
		.mumb-server-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:.85rem;font-weight:600;}
		.mumb-server-badge-online{background:#dcfce7;color:#166534;}
		.mumb-server-badge-degraded{background:#fef3c7;color:#92400e;}
		.mumb-server-badge-offline{background:#fee2e2;color:#991b1b;}
		.mumb-server-meta{display:flex;flex-direction:column;gap:4px;color:#475569;font-size:.9rem;}
		.mumb-server-loading,.mumb-server-error{text-align:center;color:#475569;}
		</style>
		<?php
	}

	return ob_get_clean();
} );
    });
})();
</script>
JS,
            wp_json_encode( $rest_base ),
            wp_json_encode( $rest_nonce ),
            wp_json_encode( $messages )
        );
    }

    return ob_get_clean();
} );
