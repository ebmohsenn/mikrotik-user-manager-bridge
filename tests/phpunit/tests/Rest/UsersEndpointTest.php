<?php
use PHPUnit\Framework\TestCase;

class UsersEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('rest_get_server')) {
            $this->markTestSkipped('WordPress test environment not available.');
        }
        
        // Create admin user for authentication
        $user_id = wp_create_user('admin_test', 'password123', 'admin@example.com');
        $user = new WP_User($user_id);
        $user->set_role('administrator');
        wp_set_current_user($user_id);
    }

    public function test_users_endpoint_returns_expected_schema(): void
    {
        $request = new WP_REST_Request('GET', '/mwp/v1/users');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $first = $data[0];
        $this->assertArrayHasKey('username', $first);
        $this->assertArrayHasKey('group', $first);
        $this->assertArrayHasKey('shared-users', $first);
    }
}
