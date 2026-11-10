<?php

use MUMB\Services\SettingsService;
use PHPUnit\Framework\TestCase;

class SettingsServiceTest extends TestCase {
    protected function setUp() : void {
        if ( ! function_exists( 'update_option' ) ) {
            $this->markTestSkipped( 'WordPress option API not available.' );
        }
        
        // Initialize Brain Monkey for function mocking
        \Brain\Monkey\setUp();
        
        // Define AUTH_KEY to avoid wp_salt call that causes Patchwork issues
        if (!defined('AUTH_KEY')) {
            define('AUTH_KEY', 'test-auth-key-for-testing-purposes');
        }
        
        $this->setupWpdbMock();
        parent::setUp();
    }

    private function setupWpdbMock() : void {
        $wpdb = new class() {
            public $prefix = 'wp_';
            public $options = 'wp_options';
            public $users = 'wp_users';
            
            private $options_data = [];
            
            public function prepare( $query, ...$args ) { 
                return vsprintf(str_replace('%s', "'%s'", $query), $args);
            }
            
            public function get_row( $query, $output = ARRAY_A ) {
                // Extract option name from query for get_option calls
                if (preg_match("/option_name = '([^']+)'/", $query, $matches)) {
                    $option_name = $matches[1];
                    if (isset($this->options_data[$option_name])) {
                        return ['option_value' => $this->options_data[$option_name]];
                    }
                }
                return null;
            }
            
            public function get_results( $query, $output = ARRAY_A ) {
                return [];
            }
            
            public function get_col( $query, $column_offset = 0 ) {
                return [];
            }
            
            public function query( $query ) {
                return 1; // Simulate successful query
            }
            
            public function insert( $table, $data, $format = null ) {
                return 1;
            }
            
            public function update( $table, $data, $where, $format = null, $where_format = null ) {
                return 1;
            }
            
            public function delete( $table, $where, $where_format = null ) {
                return 1;
            }
            
            public function replace( $table, $data, $format = null ) {
                return 1;
            }
            
            // Store options data for testing
            public function set_option_data( $name, $value ) {
                $this->options_data[$name] = $value;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function tearDown() : void {
        \Brain\Monkey\tearDown();
        if ( function_exists( 'delete_option' ) ) {
            delete_option( 'mumb_profile_product_map' );
            delete_option( 'mumb_master_mode' );
            delete_option( 'mumb_cli_token_hash' );
            delete_option( 'mumb_cli_token_alg' );
            delete_option( 'mumb_cli_token_hint' );
            delete_option( 'mumb_cli_token_created' );
            delete_option( 'mumb_affiliate_default_group' );
            delete_option( 'mumb_keepalive_state' );
            delete_option( 'mumb_job_queue' );
            delete_option( 'mumb_job_history' );
        }
        parent::tearDown();
    }

    public function test_profile_map_round_trip() : void {
        SettingsService::set_profile_map( [ 'Basic' => 123, 'Pro' => 456 ] );
        $map = SettingsService::get_profile_map();
        $this->assertSame( [ 'Basic' => 123, 'Pro' => 456 ], $map );
    }

    public function test_master_mode_toggle() : void {
        SettingsService::set_master_mode( true );
        $this->assertTrue( SettingsService::is_master_mode() );
        SettingsService::set_master_mode( false );
        $this->assertFalse( SettingsService::is_master_mode() );
    }

    public function test_cli_token_management() : void {
        // Clear any existing CLI token data from previous tests
        delete_option( 'mumb_cli_token_hash' );
        delete_option( 'mumb_cli_token_alg' );
        delete_option( 'mumb_cli_token_hint' );
        delete_option( 'mumb_cli_token_created' );
        
        SettingsService::set_cli_token( 'hash-value', 'hmac-sha256', 'abcd', '2024-01-01 00:00:00' );
        $token = SettingsService::get_cli_token();
        $this->assertSame( 'hash-value', $token['hash'] );
        $this->assertSame( 'hmac-sha256', $token['alg'] );
        $this->assertSame( 'abcd', $token['hint'] );
        $this->assertSame( '2024-01-01 00:00:00', $token['created'] );
        $stored_hash = get_option( 'mumb_cli_token_hash' );
        $this->assertIsString( $stored_hash );
        $this->assertStringStartsWith( 'enc:', $stored_hash );
        $stored_hint = get_option( 'mumb_cli_token_hint' );
        $this->assertIsString( $stored_hint );
        $this->assertStringStartsWith( 'enc:', $stored_hint );

        SettingsService::clear_cli_token();
        $cleared = SettingsService::get_cli_token();
        $this->assertSame( '', $cleared['hash'] );
        $this->assertSame( '', $cleared['hint'] );

        update_option( 'mumb_cli_token_hash', 'legacy-hash', false );
        update_option( 'mumb_cli_token_hint', 'wxyz', false );
        $migrated = SettingsService::get_cli_token();
        $this->assertSame( 'legacy-hash', $migrated['hash'] );
        $this->assertSame( 'wxyz', $migrated['hint'] );
        $this->assertStringStartsWith( 'enc:', (string) get_option( 'mumb_cli_token_hash' ) );
        $this->assertStringStartsWith( 'enc:', (string) get_option( 'mumb_cli_token_hint' ) );
    }

    public function test_affiliate_default_group_helpers() : void {
        // Clear any existing affiliate default group from previous tests
        delete_option( 'mumb_affiliate_default_group' );
        
        SettingsService::set_affiliate_default_group( 'Partners' );
        $this->assertSame( 'Partners', SettingsService::get_affiliate_default_group() );
        SettingsService::set_affiliate_default_group( null );
        $this->assertSame( '', SettingsService::get_affiliate_default_group() );
    }

    public function test_queue_helpers() : void {
        SettingsService::set_job_queue( [ 'job-1' => [ 'payload' => [ 'type' => 'test' ] ] ] );
        $queue = SettingsService::get_job_queue();
        $this->assertArrayHasKey( 'job-1', $queue );

        SettingsService::set_job_history( [ [ 'key' => 'job-1', 'status' => 'success' ] ] );
        $history = SettingsService::get_job_history();
        $this->assertSame( 'job-1', $history[0]['key'] );

        SettingsService::set_keepalive_state( [ 'failures' => 2 ] );
        $keepalive = SettingsService::get_keepalive_state();
        $this->assertSame( 2, $keepalive['failures'] );
    }
}
