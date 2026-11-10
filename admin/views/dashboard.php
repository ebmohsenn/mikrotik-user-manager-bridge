<?php

use MUMB\Services\SettingsService;

$state = SettingsService::get_connection_state();
$connected = ! empty( $state['connected'] );
$last_error = '';
if ( isset( $state['last_error'] ) && is_scalar( $state['last_error'] ) ) {
    $last_error = esc_html( (string) $state['last_error'] );
}
$api_version = '';
if ( isset( $state['api_version'] ) && is_scalar( $state['api_version'] ) ) {
    $api_version = esc_html( (string) $state['api_version'] );
}

/** @var wpdb|null $mumb_wpdb */
$mumb_wpdb = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ? $GLOBALS['wpdb'] : null;
if ( ! $mumb_wpdb ) {
    return;
}
$p = $mumb_wpdb->prefix;
$users_value = $mumb_wpdb->get_var( 'SELECT COUNT(*) FROM ' . $p . 'mikrotik_users' );
$users = is_numeric( $users_value ) ? (int) $users_value : 0;
$profiles_value = $mumb_wpdb->get_var( 'SELECT COUNT(*) FROM ' . $p . 'mikrotik_profiles' );
$profiles = is_numeric( $profiles_value ) ? (int) $profiles_value : 0;
$logs_value = $mumb_wpdb->get_var( 'SELECT COUNT(*) FROM ' . $p . 'mikrotik_logs' );
$logs = is_numeric( $logs_value ) ? (int) $logs_value : 0;
$sync_raw = get_option( 'mumb_sync_state', [] );
$sync = is_array( $sync_raw ) ? $sync_raw : [];
$last_sync = '-';
if ( isset( $sync['last_sync'] ) && is_scalar( $sync['last_sync'] ) && $sync['last_sync'] !== '' ) {
    $last_sync = esc_html( (string) $sync['last_sync'] );
}
$last_dur = isset( $sync['last_duration_ms'] ) && is_numeric( $sync['last_duration_ms'] ) ? (int) $sync['last_duration_ms'] : 0;

$connection_blurb = $connected ? esc_html__( 'Connected', 'mikrotik-user-manager-bridge' ) : esc_html__( 'Disconnected', 'mikrotik-user-manager-bridge' );
$version_suffix = $api_version ? ( ' — v' . $api_version ) : '';

echo '<div class="notice ' . esc_attr( $connected ? 'notice-success' : 'notice-error' ) . '"><p><strong>' . esc_html__( 'Connection', 'mikrotik-user-manager-bridge' ) . ':</strong> ' . esc_html( $connection_blurb ) . esc_html( $version_suffix ) . '</p></div>';
echo '<p><strong>' . esc_html__( 'Last Sync', 'mikrotik-user-manager-bridge' ) . ':</strong> ' . esc_html( $last_sync ?: '-' ) . ' (' . esc_html( (string) $last_dur ) . ' ms)</p>';
if ( $last_error ) {
    echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Last Error', 'mikrotik-user-manager-bridge' ) . ':</strong> ' . esc_html( $last_error ) . '</p></div>';
}
echo '<h2>' . esc_html__( 'Quick Stats', 'mikrotik-user-manager-bridge' ) . '</h2>';
echo '<ul>';
echo '<li>' . esc_html__( 'Users', 'mikrotik-user-manager-bridge' ) . ': ' . esc_html( (string) $users ) . '</li>';
echo '<li>' . esc_html__( 'Profiles', 'mikrotik-user-manager-bridge' ) . ': ' . esc_html( (string) $profiles ) . '</li>';
echo '<li>' . esc_html__( 'Logs', 'mikrotik-user-manager-bridge' ) . ': ' . esc_html( (string) $logs ) . '</li>';
echo '</ul>';
echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mumb_sync_now' ), 'mumb_sync_now' ) ) . '">' . esc_html__( 'Sync Now', 'mikrotik-user-manager-bridge') . '</a> ';
echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mikrotik_logs' ) ) . '">' . esc_html__( 'View Logs', 'mikrotik-user-manager-bridge') . '</a></p>';
