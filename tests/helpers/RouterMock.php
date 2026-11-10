<?php

/**
 * Lightweight helper for RouterOS integration tests.
 * Provides deterministic responses for both binary and REST clients.
 */
class RouterMock {
    public array $binaryCalls = [];
    public array $restCalls = [];
    public array $responses = [];

    public function __construct() {
        $this->responses = [
            'binary' => [
                'get_users' => [ 'status' => 'ok', 'data' => [ [ 'username' => 'alice', '.id' => '*1' ] ] ],
                'get_groups' => [ 'status' => 'ok', 'data' => [ [ 'name' => 'default' ] ] ],
            ],
            'rest' => [
                'get_users' => [ 'status' => 'ok', 'data' => [ [ 'name' => 'alice', 'id' => '1' ] ] ],
            ],
        ];
    }

    public function recordBinary( string $method, array $payload = [] ) : array {
        $this->binaryCalls[] = [ 'method' => $method, 'payload' => $payload ];
        return $this->responses['binary'][ $method ] ?? [ 'status' => 'ok', 'data' => true ];
    }

    public function recordRest( string $method, array $payload = [] ) : array {
        $this->restCalls[] = [ 'method' => $method, 'payload' => $payload ];
        return $this->responses['rest'][ $method ] ?? [ 'status' => 'ok', 'data' => true ];
    }
}
