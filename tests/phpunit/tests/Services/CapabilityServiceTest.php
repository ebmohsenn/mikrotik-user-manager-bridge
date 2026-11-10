<?php

use MUMB\Services\CapabilityService;
use MUMB\Services\SettingsService;
use PHPUnit\Framework\TestCase;

class CapabilityServiceTest extends TestCase {
    protected function setUp() : void {
        if ( ! function_exists( 'update_option' ) ) {
            $this->markTestSkipped( 'WordPress option API not available.' );
        }
        $this->setupWpdbMock();
        parent::setUp();
        SettingsService::set_connection_state( [] );
        SettingsService::save_settings( MUMB_Settings::load() );
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
        if ( function_exists( 'delete_option' ) ) {
            delete_option( 'mumb_connection_state' );
        }
        parent::tearDown();
    }

    public function test_probe_without_credentials_returns_defaults() : void {
        $caps = ( new CapabilityService() )->probe();
        $this->assertArrayHasKey( 'binary', $caps );
        $this->assertFalse( (bool) ( $caps['binary']['available'] ?? true ) );

        $state = SettingsService::get_connection_state();
        $this->assertArrayHasKey( 'capabilities', $state );
        $this->assertSame( $caps, $state['capabilities'] );
    }

    public function test_get_capabilities_uses_cached_state() : void {
        $cached = [ 'binary' => [ 'available' => true ], 'rest' => [ 'available' => false ], 'profile_updates' => [ 'binary' => true, 'rest' => false ], 'quirks' => [ 'user_profile_field' => 'user' ] ];
        SettingsService::set_connection_state( [ 'capabilities' => $cached ] );
        $service = new CapabilityService();
        $this->assertSame( $cached, $service->get_capabilities() );
    }
}
