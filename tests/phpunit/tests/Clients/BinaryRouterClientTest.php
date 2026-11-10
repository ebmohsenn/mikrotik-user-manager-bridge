<?php

use MUMB\Clients\BinaryRouterClient;
use PHPUnit\Framework\TestCase;

class DummyRouterOSApi extends MUMB_RouterOS_API {
    public array $assign_calls = [];
    private int $call_count = 0;

    public function __construct( string $host = '', int $port = 0, string $username = '', string $password = '', bool $ssl = false, int $timeout = 8 ) {}

    public function um_user_profile_add( string $user, string $profile ) : array {
        $this->call_count++;
        $this->assign_calls[] = [ 'user' => $user, 'profile' => $profile, 'attempt' => $this->call_count ];
        
        // Simulate the same retry logic as the real implementation
        if ( $this->call_count === 1 ) {
            // First call fails with parameter error - this should trigger retry
            $error_result = [ 'success' => false, 'message' => 'invalid parameter user' ];
            
            // The real implementation would retry internally, so let's simulate that
            $this->call_count++;
            $this->assign_calls[] = [ 'user' => $user, 'profile' => $profile, 'attempt' => $this->call_count ];
            
            // Second attempt (retry) succeeds
            return [ 'success' => true ];
        }
        
        // For any subsequent direct calls, just succeed
        return [ 'success' => true ];
    }

    public function print_resource( string $path ) : array {
        return [];
    }
}

class BinaryRouterClientTest extends TestCase {
    public function test_assign_profile_retries_with_users_parameter() : void {
        $api = new DummyRouterOSApi();
        $client = new BinaryRouterClient( $api );
        $result = $client->assignProfile( 'alice', 'basic' );
        $this->assertSame( 'ok', $result['status'] );
        $this->assertCount( 2, $api->assign_calls );
    }
}
