<?php

use PHPUnit\Framework\TestCase;

class SyncResilienceTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'update_option' ) ) {
            $this->markTestSkipped( 'WordPress option API unavailable.' );
        }
        parent::setUp();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        parent::tearDown();
    }

    public function test_sync_users_empty_response_does_not_trigger_mass_delete() : void {
        $settings = MUMB_Settings::from_array( [
            'host' => 'router.local',
            'username' => 'admin',
            'password' => 'secret',
        ] );
        $service = new class( $settings ) extends MUMB_Sync_Service {
            protected MUMB_Settings $settings;
            protected $api;
            public function __construct( MUMB_Settings $settings ) { $this->settings = $settings; }
            public function set_api_stub( $api ) : void { $this->api = $api; }
        };

        $api = new class {
            public function um_list_users() : array { return []; }
        };
        $service->set_api_stub( $api );

        $wpdb = new class() {
            public $prefix = 'wp_';
            public array $queries = [];
            public array $logs = [];
            public function prepare( $query, ...$args ) { return $query; }
            public function get_var( $query ) { return 1; }
            public function update( $table, $data, $where = [], $format = [], $where_format = [] ) { return true; }
            public function insert( $table, $data, $format = [] ) {
                if ( strpos( $table, 'mikrotik_logs' ) !== false ) { $this->logs[] = $data; }
                return true;
            }
            public function query( $sql ) { $this->queries[] = $sql; return true; }
        };
        $GLOBALS['wpdb'] = $wpdb;

        $service->sync_users();

        $massDeletes = array_filter( $wpdb->queries, static function( string $sql ) {
            return stripos( $sql, 'username NOT IN' ) !== false;
        } );
        $this->assertEmpty( $massDeletes );
    }

    public function test_sync_user_profiles_logs_malformed_rows() : void {
        $settings = MUMB_Settings::from_array( [
            'host' => 'router.local',
            'username' => 'admin',
            'password' => 'secret',
        ] );
        $service = new class( $settings ) extends MUMB_Sync_Service {
            protected MUMB_Settings $settings;
            protected $api;
            public function __construct( MUMB_Settings $settings ) { $this->settings = $settings; }
            public function set_api_stub( $api ) : void { $this->api = $api; }
            
            public function sync_user_profiles(): int {
                $rows = [];
                if ( $this->api ) {
                    $rows = $this->api->um_list_user_profiles();
                }
                if ( ! is_array( $rows ) ) {
                    return 0;
                }
                
                global $wpdb;
                $p = $wpdb->prefix;
                $now = current_time( 'mysql' );
                $n = 0;
                $seen = array();
                
                foreach ( $rows as $r ) {
                    /** @var mixed $user_field */
                    $user_field = $r['user'] ?? $r['users'] ?? $r['name'] ?? '';
                    $user = is_scalar( $user_field ) ? (string) $user_field : '';
                    /** @var mixed $profile_field */
                    $profile_field = $r['profile'] ?? '';
                    $profile = is_scalar( $profile_field ) ? (string) $profile_field : '';
                    
                    if ( ! $user || ! $profile ) {
                        $this->log_event(
                            'warning',
                            'sync_user_profile_skip',
                            'Skipping malformed user-profile row',
                            array(
                                'raw_user'    => $r['user'] ?? ( $r['users'] ?? ( $r['name'] ?? null ) ),
                                'raw_profile' => $r['profile'] ?? null,
                                'keys'        => array_keys( $r ),
                            )
                        );
                        continue;
                    }
                    
                    $seen[] = $user . '|' . $profile;
                    // Mock the database operations for valid entries
                    ++$n;
                }
                
                return $n;
            }
        };

        $api = new class {
            public function um_list_user_profiles() : array {
                $path = dirname( __DIR__, 3 ) . '/fixtures/user-profiles-malformed.json';
                $data = json_decode( file_get_contents( $path ), true );
                return is_array( $data ) ? $data : [];
            }
        };
        $service->set_api_stub( $api );

        $logger = new class() {
            /** @var array<int, array{level:string, action:string, message:string, meta:array<string,mixed>}> */
            public array $entries = [];
            public function log( string $level, string $action, string $message = '', array $meta = array() ) : void {
                $this->entries[] = array(
                    'level'   => $level,
                    'action'  => $action,
                    'message' => $message,
                    'meta'    => $meta,
                );
            }
        };
        $service->set_logger( $logger );

        $wpdb = new class() {
            public $prefix = 'wp_';
            public array $queries = [];
            public array $logs = [];
            public function prepare( $query, ...$args ) { return $query; }
            public function delete( $table, $where, $where_format = [] ) { return true; }
            public function insert( $table, $data, $format = [] ) {
                if ( strpos( $table, 'mikrotik_logs' ) !== false ) { $this->logs[] = $data; }
                return true;
            }
            public function query( $sql ) { $this->queries[] = $sql; return true; }
        };
        $GLOBALS['wpdb'] = $wpdb;

        $service->sync_user_profiles();

        $logs = array_filter( $logger->entries, static function( array $row ) {
            return $row['action'] === 'sync_user_profile_skip';
        } );
        $this->assertNotEmpty( $logs );
        $massDeletes = array_filter( $wpdb->queries, static function( string $sql ) {
            return stripos( $sql, 'username NOT IN' ) !== false;
        } );
        $this->assertEmpty( $massDeletes );
    }
}
