<?php

use MUMB\Services\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Passwords that travel through the job queue must never be stored or returned in clear.
 */
class QueueSecretsTest extends TestCase {
    private const PASSWORD = 'S3cret-pass!42';

    /** @var mixed */
    private $previousWpdb;

    protected function setUp(): void {
        parent::setUp();
        if ( ! function_exists( 'update_option' ) ) {
            $this->markTestSkipped( 'WordPress options API unavailable.' );
        }
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        // A wpdb whose mirror table knows the user (um_id/rest_id) and counts one matching row.
        $GLOBALS['wpdb'] = new class( 'wp_' ) extends wpdb {
            public function get_var( $query ) {
                return 1;
            }
            public function get_row( $query, $output = ARRAY_A ) {
                if ( strpos( (string) $query, 'mikrotik_users' ) !== false ) {
                    return [ 'um_id' => '*1', 'rest_id' => '11' ];
                }
                return parent::get_row( $query, $output );
            }
        };
        MikroTik_API::$calls    = [];
        MikroTik_API::$failures = [];
        SettingsService::save_settings( MUMB_Settings::from_array( [
            'host'        => 'router.local',
            'username'    => 'admin',
            'password'    => 'secret',
            'port'        => 8728,
            'ssl'         => false,
            'api_timeout' => 5,
        ] ) );
        SettingsService::set_job_queue( [] );
        SettingsService::set_job_history( [] );
    }

    protected function tearDown(): void {
        MikroTik_API::$failures = [];
        SettingsService::set_job_queue( [] );
        SettingsService::set_job_history( [] );
        $GLOBALS['wpdb'] = $this->previousWpdb;
        parent::tearDown();
    }

    public function test_password_reset_is_stored_encrypted_runs_and_leaves_no_secret_behind() : void {
        MUMB_Woo_Integration::handle_password_reset( new WP_User( 10, 'alice' ), self::PASSWORD );

        $queue = SettingsService::get_job_queue();
        $this->assertArrayHasKey( 'wc_update_user_alice_none', $queue );
        $stored = $queue['wc_update_user_alice_none']['payload'];
        $this->assertStringNotContainsString( self::PASSWORD, (string) json_encode( $queue ) );
        $this->assertArrayNotHasKey( 'password', $stored['data'] );
        $this->assertStringStartsWith( 'enc:', $stored['data']['password_enc'] );

        // What the REST endpoints return (/mwp/v1/queue and /mwp/v1/affiliate/queue) holds no form of it.
        $snapshot = (string) json_encode( MUMB_Queue::get_queue_snapshot() );
        $this->assertStringNotContainsString( self::PASSWORD, $snapshot );
        $this->assertStringNotContainsString( 'password', $snapshot );

        // The job finds the user under `user` (it used to look only for `username` and fail) and
        // sends the decrypted password to the router.
        $result = MUMB_Queue::process();
        $this->assertSame( 1, $result['processed'] );
        $updates = array_values( array_filter( MikroTik_API::$calls, static fn( $call ) => 'update_user' === $call['method'] ) );
        $this->assertCount( 1, $updates );
        $this->assertSame( '11', $updates[0]['payload']['id'] );
        $this->assertSame( self::PASSWORD, $updates[0]['payload']['data']['password'] );

        $history = (string) json_encode( SettingsService::get_job_history() );
        $this->assertStringContainsString( 'wc_update_user_alice_none', $history );
        $this->assertStringNotContainsString( self::PASSWORD, $history );
        $this->assertStringNotContainsString( 'password', $history );
    }

    public function test_failed_job_history_holds_no_password() : void {
        MUMB_Queue::enqueue( 'manual_update_bob', [
            'type'     => 'update_user',
            'username' => 'bob',
            'password' => self::PASSWORD,
        ] );
        $attempts = 0;
        while ( ! empty( SettingsService::get_job_queue() ) && $attempts < 10 ) {
            MikroTik_API::$failures['update_user'] = 1;
            $queue = SettingsService::get_job_queue();
            $queue['manual_update_bob']['next_run'] = time() - 1;
            SettingsService::set_job_queue( $queue );
            MUMB_Queue::process();
            ++$attempts;
        }
        $this->assertEmpty( SettingsService::get_job_queue(), 'The job should have been given up on.' );

        $history = SettingsService::get_job_history();
        $this->assertSame( 'failed', $history[0]['status'] );
        $encoded = (string) json_encode( $history );
        $this->assertStringNotContainsString( self::PASSWORD, $encoded );
        $this->assertStringNotContainsString( 'password', $encoded );
    }

    public function test_upgrade_scrubs_passwords_left_by_older_versions() : void {
        // The shapes 2.1.1 wrote: clear text in the history and in a job still waiting to run.
        SettingsService::set_job_history( [
            [
                'key'     => 'wc_update_user_carol_none',
                'type'    => 'update_user',
                'status'  => 'failed',
                'payload' => [ 'type' => 'update_user', 'user' => 'carol', 'data' => [ 'password' => self::PASSWORD ] ],
            ],
            [
                'key'     => 'other',
                'type'    => 'create_user',
                'status'  => 'success',
                'payload' => [ 'type' => 'create_user', 'user' => 'dave', 'password' => self::PASSWORD ],
            ],
        ] );
        SettingsService::set_job_queue( [
            'wc_update_user_erin_none' => [
                'payload'  => [ 'type' => 'update_user', 'user' => 'erin', 'data' => [ 'password' => self::PASSWORD ] ],
                'attempts' => 0,
                'next_run' => time() - 1,
                'status'   => 'pending',
            ],
        ] );

        MUMB_Queue::scrub_stored_secrets();

        $history = SettingsService::get_job_history();
        $this->assertStringNotContainsString( self::PASSWORD, (string) json_encode( $history ) );
        $this->assertStringNotContainsString( 'password', (string) json_encode( $history ) );
        $this->assertSame( 'carol', $history[0]['payload']['user'] );

        $queue = SettingsService::get_job_queue();
        $this->assertStringNotContainsString( self::PASSWORD, (string) json_encode( $queue ) );
        $this->assertStringStartsWith( 'enc:', $queue['wc_update_user_erin_none']['payload']['data']['password_enc'] );

        // The waiting job still does its work after the scrub.
        $result = MUMB_Queue::process();
        $this->assertSame( 1, $result['processed'] );
        $updates = array_values( array_filter( MikroTik_API::$calls, static fn( $call ) => 'update_user' === $call['method'] ) );
        $this->assertSame( self::PASSWORD, $updates[0]['payload']['data']['password'] );
    }

    public function test_redact_payload_removes_every_form_of_a_password() : void {
        $clean = MUMB_Queue::redact_payload( [
            'type'         => 'update_user',
            'user'         => 'frank',
            'password'     => 'a',
            'password_enc' => 'enc:b',
            'data'         => [ 'password' => 'c', 'password_enc' => 'enc:d', 'comment' => 'kept' ],
        ] );
        $this->assertSame(
            [ 'type' => 'update_user', 'user' => 'frank', 'data' => [ 'comment' => 'kept' ] ],
            $clean
        );
    }

    public function test_router_user_created_from_a_job_without_password_gets_a_random_one() : void {
        MUMB_Queue::enqueue( 'wc_create_user_gina_basic', [
            'type'    => 'create_user',
            'user'    => 'gina',
            'profile' => 'basic',
            'data'    => [ 'comment' => 'WooCommerce order #7' ],
        ] );
        MUMB_Queue::process();

        $creates = array_values( array_filter( MikroTik_API::$calls, static fn( $call ) => 'add_user' === $call['method'] ) );
        $this->assertCount( 1, $creates );
        $this->assertSame( 'gina', $creates[0]['payload']['username'] );
        $this->assertArrayHasKey( 'password', $creates[0]['payload'] );
        $this->assertGreaterThanOrEqual( 20, strlen( (string) $creates[0]['payload']['password'] ) );
        // The generated password is not written anywhere.
        $this->assertStringNotContainsString( (string) $creates[0]['payload']['password'], (string) json_encode( SettingsService::get_job_history() ) );
    }

    public function test_password_change_of_a_user_without_router_account_is_not_queued() : void {
        $GLOBALS['wpdb'] = new class( 'wp_' ) extends wpdb {
            public function get_var( $query ) {
                return 0;
            }
        };
        MUMB_Woo_Integration::handle_password_reset( new WP_User( 1, 'siteadmin' ), self::PASSWORD );
        $this->assertEmpty( SettingsService::get_job_queue() );
    }
}
