<?php

use MUMB\Services\SettingsService;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $order_id ) {
        return $GLOBALS['__queue_flow_order'] ?? null;
    }
}

// Don't redefine wcs_* functions - they're already in bootstrap

if ( ! class_exists( 'WC_Subscription' ) ) {
    class WC_Subscription {
        private $id;
        private $user_id;
        private $items;
        private $dates;
        public function __construct( $id, $user_id, array $items, array $dates = [] ) {
            $this->id = $id;
            $this->user_id = $user_id;
            $this->items = $items;
            $this->dates = $dates;
        }
        public function get_id() { return $this->id; }
        public function get_user_id() { return $this->user_id; }
        public function get_items() { return $this->items; }
        public function get_date( $key ) { return $this->dates[ $key ] ?? null; }
    }
}

class QueueFlowTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        if ( ! function_exists( 'update_option' ) ) {
            $this->markTestSkipped( 'WordPress options API unavailable.' );
        }
        $this->mockWpdbLookup(); // Set up wpdb early
        MikroTik_API::$calls = [];
        $this->configureSettings();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        MikroTik_API::$failures = [];
        unset( $GLOBALS['__queue_flow_order'], $GLOBALS['wpdb'], $GLOBALS['_wp_function_overrides']['get_user_by'] );
        parent::tearDown();
    }

    private function configureSettings() : void {
        SettingsService::save_settings( MUMB_Settings::from_array( [
            'host' => 'router.local',
            'username' => 'admin',
            'password' => 'secret',
            'port' => 8728,
            'ssl' => false,
            'api_timeout' => 5,
        ] ) );
        update_option( MUMB_Queue::OPTION_KEY, [] );
    }

    public function test_order_completion_flows_through_queue() : void {
        $order = new WC_Order( 55, 10, [ new WC_Order_Item_Product( 123 ) ] );
        $GLOBALS['__queue_flow_order'] = $order;

        // Create a proper WP_User mock
        $user = new WP_User();
        $user->ID = 10;
        $user->user_login = 'alice';
        $GLOBALS['_wp_function_overrides']['get_user_by'] = static function () use ( $user ) {
            return $user;
        };
        \Brain\Monkey\Functions\when( 'get_user_meta' )->justReturn( '' );
        // Don't mock wcs_* functions - they're already defined in bootstrap

        $this->mockWpdbLookup();
        $this->setProfileProductMap( [ 'basic' => 123 ] );

        MUMB_Woo_Integration::handle_order_completed( 55 );

        $queue = SettingsService::get_job_queue();
        $this->assertNotEmpty( $queue );
        $this->assertArrayHasKey( 'wc_create_user_alice_basic', $queue );

        $result = MUMB_Queue::process();
        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( 0, $result['remaining'] );

        $this->assertNotEmpty( MikroTik_API::$calls );
        $this->assertSame( 'add_user', MikroTik_API::$calls[0]['method'] );
        $this->assertSame( 'alice', MikroTik_API::$calls[0]['payload']['username'] );
    }

    public function test_subscription_cancellation_disables_user_with_retry() : void {
        $subscription = new WC_Subscription( 77, 10, [ new WC_Order_Item_Product( 123 ) ] );

        // Create a proper WP_User mock
        $user = new WP_User();
        $user->ID = 10;
        $user->user_login = 'alice';
        $GLOBALS['_wp_function_overrides']['get_user_by'] = static function () use ( $user ) {
            return $user;
        };
        $this->mockWpdbLookup();
        $this->setProfileProductMap( [ 'basic' => 123 ] );

        MUMB_Woo_Integration::handle_subscription_cancelled( $subscription );

        $queue = SettingsService::get_job_queue();
        $this->assertArrayHasKey( 'wc_disable_user_alice_basic', $queue );
        $payload = $queue['wc_disable_user_alice_basic']['payload'];
        $this->assertSame( 'disable_user', $payload['type'] );
        $this->assertTrue( $payload['data']['remove_profiles'] );

        MikroTik_API::$failures['update_user'] = 1;
        $result = MUMB_Queue::process();
        $this->assertSame( 0, $result['processed'] );

        $queue = SettingsService::get_job_queue();
        $this->assertSame( 'retrying', $queue['wc_disable_user_alice_basic']['status'] );
        $this->assertSame( 1, $queue['wc_disable_user_alice_basic']['attempts'] );
        $queue['wc_disable_user_alice_basic']['next_run'] = time() - 1;
        SettingsService::set_job_queue( $queue );

        MikroTik_API::$failures['update_user'] = 0;
        $result = MUMB_Queue::process();
        $this->assertSame( 1, $result['processed'] );

        $this->assertEmpty( SettingsService::get_job_queue() );
        $updates = array_filter( MikroTik_API::$calls, static fn( $call ) => $call['method'] === 'update_user' );
        $this->assertGreaterThanOrEqual( 2, count( $updates ) );
        $removals = array_filter( MikroTik_API::$calls, static fn( $call ) => $call['method'] === 'remove_user_all_profiles' );
        $this->assertCount( 1, $removals );
    }

    private function setProfileProductMap( array $map ) : void {
        // Set both the WordPress option and the static property
        update_option( 'mumb_profile_product_map', $map );
        
        // Reinitialize the WooCommerce integration to reload the profile map
        MUMB_Woo_Integration::init();
    }

    private function mockWpdbLookup() : void {
        global $wpdb;
        $wpdb = new class() {
            public $prefix = 'wp_';
            public $options = 'wp_options';
            public array $logs = [];
            
            public function prepare( $query, ...$args ) { return $query; }
            
            public function get_row( $query, $output = ARRAY_A ) {
                return [ 'um_id' => '*1', 'rest_id' => '11' ];
            }
            
            public function get_results( $query, $output = ARRAY_A ) {
                return [];
            }
            
            public function get_col( $query, $column_offset = 0 ) {
                return [];
            }
            
            public function insert( $table, $data, $format = [] ) {
                if ( strpos( $table, 'mikrotik_logs' ) !== false ) {
                    $this->logs[] = $data;
                }
                return true;
            }
            
            public function update( $table, $data, $where, $format = null, $where_format = null ) {
                return 1;
            }
            
            public function delete( $table, $where, $where_format = null ) {
                return 1;
            }
            
            public function replace( $table, $data, $format = [] ) { 
                return true; 
            }
            
            public function query( $sql ) { 
                return true; 
            }
        };
    }
}
