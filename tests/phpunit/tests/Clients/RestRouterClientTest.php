<?php

use MUMB\Clients\RestRouterClient;
use PHPUnit\Framework\TestCase;

class DummyUmRest extends MUMB_UM_REST {
    public array $updates = [];
    private array $users;

    public function __construct( array $users ) {
        $this->users = $users;
        parent::__construct( 'https://example.test', 'user', 'pass', false );
    }

    public function list_users() : array|WP_Error {
        return $this->users;
    }

    public function update_user( string $id, array $data ) : array|WP_Error {
        $this->updates[] = [ 'id' => $id, 'data' => $data ];
        if ( $id === 'alice' ) {
            return new WP_Error( 'not_found', 'user not found' );
        }
        return [ 'success' => true ];
    }
}

class RestRouterClientTest extends TestCase {
    public function test_update_user_resolves_identifier_via_listing() : void {
        if ( ! class_exists( 'WP_Error' ) ) {
            $this->markTestSkipped( 'WP_Error unavailable.' );
        }

        $rest = new DummyUmRest( [
            [ 'id' => '*1', 'name' => 'alice' ],
        ] );
        $client = new RestRouterClient( $rest );
        $result = $client->updateUser( 'alice', [ 'comment' => 'test' ] );
        $this->assertSame( 'ok', $result['status'] );
        $this->assertCount( 2, $rest->updates );
        $this->assertSame( '*1', $rest->updates[1]['id'] );
    }
}
