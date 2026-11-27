<?php

use MUMB\Clients\RouterClientInterface;
use PHPUnit\Framework\TestCase;

/**
 * The facade used to spread the data array into named arguments, so every create call died with
 * "Unknown named parameter $username".
 */
class MikroTikApiCreateTest extends TestCase {
    private function recordingClient() : RouterClientInterface {
        return new class implements RouterClientInterface {
            /** @var array<int, array{0:string,1:mixed}> */
            public array $calls = [];
            private function ok( string $method, $data ) : array {
                $this->calls[] = [ $method, $data ];
                return [ 'status' => 'ok', 'data' => true ];
            }
            public function getUsers() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function createUser( array $data ) : array { return $this->ok( __FUNCTION__, $data ); }
            public function updateUser( string $id_or_login, array $data ) : array { return [ 'status' => 'ok' ]; }
            public function deleteUser( string $id_or_login ) : array { return [ 'status' => 'ok' ]; }
            public function getProfiles() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function createProfile( array $data ) : array { return $this->ok( __FUNCTION__, $data ); }
            public function updateProfile( string $id_or_name, array $data ) : array { return [ 'status' => 'ok' ]; }
            public function deleteProfile( string $id_or_name ) : array { return [ 'status' => 'ok' ]; }
            public function getGroups() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function createGroup( array $data ) : array { return $this->ok( __FUNCTION__, $data ); }
            public function updateGroup( string $id_or_name, array $data ) : array { return [ 'status' => 'ok' ]; }
            public function deleteGroup( string $id_or_name ) : array { return [ 'status' => 'ok' ]; }
            public function assignProfile( string $username, string $profile ) : array { return [ 'status' => 'ok' ]; }
            public function removeProfile( string $username, string $profile ) : array { return [ 'status' => 'ok' ]; }
            public function removeAllProfiles( string $username ) : array { return [ 'status' => 'ok' ]; }
            public function getSessions() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function getUserProfiles() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function getUserUsageCounters() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function getRouters() : array { return [ 'status' => 'ok', 'data' => [] ]; }
            public function createRouter( array $data ) : array { return $this->ok( __FUNCTION__, $data ); }
            public function updateRouter( string $id, array $data ) : array { return [ 'status' => 'ok' ]; }
            public function deleteRouter( string $id ) : array { return [ 'status' => 'ok' ]; }
        };
    }

    public function test_create_calls_pass_the_data_array_through() : void {
        $client = $this->recordingClient();
        $api    = new MikroTik_API( [], null, $client, null, null, null );

        $user = [ 'username' => 'alice', 'password' => 'pw', 'group' => 'basic' ];
        $this->assertSame( 'ok', $api->add_user( $user )['status'] );
        $group = [ 'name' => 'basic' ];
        $this->assertSame( 'ok', $api->add_group( $group )['status'] );
        $profile = [ 'name' => 'p-1', 'validity' => '30d' ];
        $this->assertSame( 'ok', $api->create_profile( $profile )['status'] );

        $this->assertSame(
            [ [ 'createUser', $user ], [ 'createGroup', $group ], [ 'createProfile', $profile ] ],
            $client->calls
        );
    }
}
