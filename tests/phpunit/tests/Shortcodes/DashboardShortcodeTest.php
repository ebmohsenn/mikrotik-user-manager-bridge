<?php

use PHPUnit\Framework\TestCase;

class DashboardShortcodeTest extends TestCase {
    private $shortcodeCallback;

    protected function setUp(): void {
        parent::setUp();
        // Skip Brain Monkey setup since we're using real WordPress functions
        $this->registerShortcode();
        if ( function_exists( 'wp_set_current_user' ) ) {
            wp_set_current_user( 1 );
        }
    }

    protected function tearDown(): void {
        // Remove shortcode after test
        if (shortcode_exists('mikrotik_dashboard')) {
            remove_shortcode('mikrotik_dashboard');
        }
        \Mockery::close();
        unset( $GLOBALS['wpdb'] );
        parent::tearDown();
    }

    private function registerShortcode() : void {
        // The shortcode is registered automatically when the plugin loads
        // Just verify it exists
        if (!shortcode_exists('mikrotik_dashboard')) {
            // If not registered, include the shortcode file directly
            require_once dirname(__DIR__, 4) . '/public/shortcode.php';
        }
        $this->assertTrue( shortcode_exists( 'mikrotik_dashboard' ) );
    }

    public function test_shortcode_prompts_user_to_login_when_not_authenticated() : void {
        // Mock not being logged in
        add_filter('determine_current_user', '__return_zero');
        if ( function_exists( 'wp_set_current_user' ) ) {
            wp_set_current_user( 0 );
        }

        $output = do_shortcode('[mikrotik_dashboard]');
        $this->assertStringContainsString( 'Please log in to view your', $output );
        $this->assertStringContainsString( 'dashboard', $output );
        
        // Clean up
        remove_filter('determine_current_user', '__return_zero');
    }

    public function test_shortcode_renders_dashboard_for_logged_in_user() : void {
        // Create a test user and log them in
        $user_id = wp_create_user('shortcode_test_user', 'password123', 'shortcode@example.com');
        wp_set_current_user($user_id);
        
        // Set up mock wpdb for the shortcode's database queries
        $wpdb = new class() {
            public $prefix = 'wp_';
            public function prepare( $query, ...$args ) { return $query; }
            public function get_row( $query, $output = ARRAY_A ) { 
                return [ 'profile' => 'basic', 'comment' => 'welcome', 'updated_at' => '2024-01-01 00:00:00' ]; 
            }
            public function get_col( $query ) { return [ 'basic' ]; }
        };
        $GLOBALS['wpdb'] = $wpdb;

        $output = do_shortcode('[mikrotik_dashboard]');
        $this->assertStringContainsString( 'Username', $output );
        $this->assertStringContainsString( 'shortcode_test_user', $output );
        
        // Clean up
        wp_set_current_user(0);
    }
}
