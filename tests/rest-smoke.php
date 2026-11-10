<?php
// Usage: WP_LOAD=/path/to/wordpress/wp-load.php php tests/rest-smoke.php
// (defaults to the WordPress install three levels above the plugin: wp-content/plugins/<plugin>/)
$bootstrap = getenv( 'WP_LOAD' ) ?: dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $bootstrap ) ) {
    fwrite( STDERR, "Could not locate wp-load.php at {$bootstrap}\n" );
    exit( 1 );
}
require $bootstrap;

if ( ! function_exists( 'wp_set_current_user' ) ) {
    fwrite( STDERR, "WordPress failed to bootstrap.\n" );
    exit( 1 );
}

$user_id = 1;
$env_user = getenv( 'SMOKE_TEST_USER_ID' );
if ( false !== $env_user && $env_user !== '' ) {
    $user_id = (int) $env_user;
}
wp_set_current_user( $user_id );

$routes = [
    [ 'label' => 'Health', 'method' => 'GET', 'route' => '/mwp/v1/health' ],
    [ 'label' => 'Settings', 'method' => 'GET', 'route' => '/mwp/v1/settings' ],
    [ 'label' => 'Sync Diff', 'method' => 'GET', 'route' => '/mwp/v1/sync/diff' ],
];

$server = rest_get_server();
$all_ok = true;
foreach ( $routes as $item ) {
    $req = new WP_REST_Request( $item['method'], $item['route'] );
    $response = $server->dispatch( $req );
    if ( is_wp_error( $response ) ) {
        printf( "%-12s => WP_Error: %s\n", $item['label'], $response->get_error_message() );
        $all_ok = false;
        continue;
    }
    $status = $response->get_status();
    $data = $response->get_data();
    $message = '';
    if ( is_array( $data ) && isset( $data['message'] ) ) {
        $message = (string) $data['message'];
    }
    printf( "%-12s => HTTP %s %s\n", $item['label'], $status, $message );
    if ( $status >= 500 ) {
        $all_ok = false;
    }
}

exit( $all_ok ? 0 : 1 );
