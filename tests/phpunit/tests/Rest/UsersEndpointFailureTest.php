<?php
use PHPUnit\Framework\TestCase;

class UsersEndpointFailureTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('rest_get_server')) {
            $this->markTestSkipped('WordPress test environment not available.');
        }
        
        // Create admin user for authentication
        $user_id = wp_create_user('admin_failure_test', 'password123', 'admin-failure@example.com');
        $user = new WP_User($user_id);
        $user->set_role('administrator');
        wp_set_current_user($user_id);
    }

    protected function tearDown(): void
    {
        MikroTik_API::$failures['get_users'] = 0;
    }

    public function test_users_endpoint_handles_router_failure(): void
    {
        MikroTik_API::$failures['get_users'] = 1;
        $request = new WP_REST_Request('GET', '/mwp/v1/users');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(500, $response->get_status());
        $this->assertFalse($response->get_data()['success']);
    }
}
