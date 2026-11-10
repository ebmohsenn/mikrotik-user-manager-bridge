<?php

use MUMB\Clients\RouterClientInterface;
use MUMB\Services\CapabilityService;
use MUMB\Services\SettingsService;
use PHPUnit\Framework\TestCase;

class MikroTikApiFallbackTest extends TestCase {
    protected function setUp() : void {
        if ( ! function_exists( 'update_option' ) ) {
            $this->markTestSkipped( 'WordPress option API not available.' );
        }
        parent::setUp();
        global $wpdb;
        if ( ! isset( $wpdb ) || ! $wpdb instanceof wpdb ) {
            $this->markTestSkipped( 'Global $wpdb unavailable.' );
        }
        $this->ensureLogsTable();

        SettingsService::set_connection_state( [
            'capabilities' => [
                'binary' => [ 'available' => true, 'dual_namespace' => true ],
                'rest'   => [ 'available' => true, 'configured' => true, 'base_path' => '/rest/user-manager' ],
                'profile_updates' => [ 'binary' => true, 'rest' => false ],
                'quirks' => [ 'user_profile_field' => 'user' ],
            ],
        ] );
    }

    public function test_rest_failure_falls_back_to_binary_and_logs() : void {
        $rest = new class implements RouterClientInterface {
            public array $calls = [];
            public function getUsers() : array {
                $this->calls[] = __FUNCTION__;
                return [ 'status' => 'error', 'error_message' => 'REST unavailable' ];
            }
            public function createUser( array $data ) : array { return [ 'status' => 'error' ]; }
            public function updateUser( string $id_or_login, array $data ) : array { return [ 'status' => 'error' ]; }
            public function deleteUser( string $id_or_login ) : array { return [ 'status' => 'error' ]; }
            public function getProfiles() : array { return [ 'status' => 'error' ]; }
            public function createProfile( array $data ) : array { return [ 'status' => 'error' ]; }
            public function updateProfile( string $id_or_name, array $data ) : array { return [ 'status' => 'error' ]; }
            public function deleteProfile( string $id_or_name ) : array { return [ 'status' => 'error' ]; }
            public function getGroups() : array { return [ 'status' => 'error' ]; }
            public function createGroup( array $data ) : array { return [ 'status' => 'error' ]; }
            public function updateGroup( string $id_or_name, array $data ) : array { return [ 'status' => 'error' ]; }
            public function deleteGroup( string $id_or_name ) : array { return [ 'status' => 'error' ]; }
            public function assignProfile( string $username, string $profile ) : array { return [ 'status' => 'error' ]; }
            public function removeProfile( string $username, string $profile ) : array { return [ 'status' => 'error' ]; }
            public function removeAllProfiles( string $username ) : array { return [ 'status' => 'error' ]; }
            public function getSessions() : array { return [ 'status' => 'error' ]; }
            public function getUserProfiles() : array { return [ 'status' => 'error' ]; }
            public function getUserUsageCounters() : array { return [ 'status' => 'error' ]; }
            public function getRouters() : array { return [ 'status' => 'error' ]; }
            public function createRouter( array $data ) : array { return [ 'status' => 'error' ]; }
            public function updateRouter( string $id, array $data ) : array { return [ 'status' => 'error' ]; }
            public function deleteRouter( string $id ) : array { return [ 'status' => 'error' ]; }
        };

        $binary = new class implements RouterClientInterface {
            public array $calls = [];
            public function getUsers() : array {
                $this->calls[] = __FUNCTION__;
                return [ 'status' => 'ok', 'data' => [ [ 'username' => 'alice' ] ] ];
            }
            public function createUser( array $data ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function updateUser( string $id_or_login, array $data ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function deleteUser( string $id_or_login ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function getProfiles() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function createProfile( array $data ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function updateProfile( string $id_or_name, array $data ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function deleteProfile( string $id_or_name ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function getGroups() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function createGroup( array $data ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function updateGroup( string $id_or_name, array $data ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function deleteGroup( string $id_or_name ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function assignProfile( string $username, string $profile ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function removeProfile( string $username, string $profile ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function removeAllProfiles( string $username ) : array { return [ 'status' => 'ok', 'data' => 0 ]; }
            public function getSessions() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function getUserProfiles() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function getUserUsageCounters() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function getRouters() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function createRouter( array $data ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function updateRouter( string $id, array $data ) : array { return [ 'status' => 'ok', 'data' => true ]; }
            public function deleteRouter( string $id ) : array { return [ 'status' => 'ok', 'data' => true ]; }
        };

        $capsService = new class extends CapabilityService {
            public function get_capabilities( bool $force_refresh = false ) : array {
                return SettingsService::get_connection_state()['capabilities'] ?? [];
            }
        };

        $api = new MikroTik_API( [], $capsService, $rest, $binary, null, null );
        $result = $api->get_users();
        $this->assertSame( 'ok', $result['status'] );
        $this->assertCount( 1, $rest->calls );
        $this->assertCount( 1, $binary->calls );

        $logs = $this->fetchLogs( 'router_fallback' );
        $this->assertNotEmpty( $logs );
        $this->assertStringContainsString( 'binary transport', (string) $logs[0]['message'] );
    }

    public function test_param_mismatch_retry_logs() : void {
        $api = new class extends MUMB_RouterOS_API {
            public array $invocations = [];
            public function __construct() {
                parent::__construct( '127.0.0.1', 8728, 'admin', 'secret', false, 5 );
            }
            protected function um_request_dual( array $legacy_words, array $v7_words ) : array {
                $this->invocations[] = [ 'legacy' => $legacy_words, 'v7' => $v7_words ];
                if ( count( $this->invocations ) === 1 ) {
                    return [ 'success' => false, 'message' => 'Parameter user not allowed' ];
                }
                return [ 'success' => true ];
            }
        };

        $result = $api->um_user_profile_add( 'alice', 'basic' );
        $this->assertTrue( ! empty( $result['success'] ) );
        $this->assertGreaterThanOrEqual( 2, count( $api->invocations ) );

        $logs = $this->fetchLogs( 'router_param_retry' );
        $this->assertCount( 1, $logs );
        $this->assertStringContainsString( 'alternate parameter', (string) $logs[0]['message'] );
        $meta = json_decode( (string) $logs[0]['meta'], true );
        $this->assertSame( 'alice', $meta['user'] ?? null );
        $this->assertSame( 'users', $meta['alternate_key'] ?? null );
    }

    public function test_id_resolution_retry_logs() : void {
        $api = new class extends MUMB_RouterOS_API {
            public array $lastLegacy = [];
            public function __construct() {
                parent::__construct( '127.0.0.1', 8728, 'admin', 'secret', false, 5 );
            }
            public function um_list_users() : array {
                return [ [ '.id' => '*A', 'username' => 'bob' ] ];
            }
            protected function um_request_dual( array $legacy_words, array $v7_words ) : array {
                $this->lastLegacy = $legacy_words;
                return [ 'success' => true ];
            }
        };

        $result = $api->um_set_user( 'bob', [ 'comment' => 'Updated' ] );
        $this->assertTrue( ! empty( $result['success'] ) );
        $this->assertNotEmpty( $api->lastLegacy );
        $this->assertSame( '=numbers=*A', $api->lastLegacy[1] ?? '' );

        $logs = $this->fetchLogs( 'router_id_resolution' );
        $this->assertCount( 1, $logs );
        $meta = json_decode( (string) $logs[0]['meta'], true );
        $this->assertSame( 'bob', $meta['input'] ?? null );
        $this->assertSame( '*A', $meta['resolved'] ?? null );
    }

    private function ensureLogsTable() : void {
        global $wpdb;
        $table = $wpdb->prefix . 'mikrotik_logs';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, time datetime NOT NULL, level varchar(20) NOT NULL, action varchar(64) NOT NULL, message text NULL, meta longtext NULL, PRIMARY KEY (id))" );
        $wpdb->query( "TRUNCATE TABLE {$table}" );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLogs( string $action ) : array {
        global $wpdb;
        $table = $wpdb->prefix . 'mikrotik_logs';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE action=%s", $action ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }
}
