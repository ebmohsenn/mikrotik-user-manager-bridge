<?php

use Brain\Monkey\Functions;
use MUMB\Services\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * "Verify TLS certificate" (verify_ssl) and "Use TLS for the binary API" (ssl) are different settings.
 * Certificate verification must follow verify_ssl everywhere.
 */
class TlsVerificationTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: bool, 1: bool}>
     */
    public static function combinations() : array {
        return [
            'binary TLS off, verification on' => [ false, true ],
            'binary TLS on, verification off' => [ true, false ],
        ];
    }

    /**
     * @dataProvider combinations
     */
    public function test_sync_verifies_certificates_according_to_verify_ssl( bool $ssl, bool $verify ) : void {
        $settings = MUMB_Settings::from_array( [
            'host'         => 'router.local',
            'username'     => 'admin',
            'password'     => 'secret',
            'ssl'          => $ssl,
            'verify_ssl'   => $verify,
            'um_rest_base' => 'https://router.local',
        ] );
        $service = new class( $settings ) extends MUMB_Sync_Service {
            protected MUMB_Settings $settings;
            public function __construct( MUMB_Settings $settings ) { $this->settings = $settings; }
        };

        $seen = [];
        Functions\when( 'wp_remote_request' )->alias( static function ( $url, $args ) use ( &$seen ) {
            $seen[] = $args['sslverify'] ?? null;
            return new WP_Error( 'http_request_failed', 'stubbed' );
        } );

        $service->sync_users();
        $service->sync_groups();
        $service->sync_profiles();

        $this->assertNotEmpty( $seen );
        $this->assertSame( [ $verify ], array_values( array_unique( $seen, SORT_REGULAR ) ) );
    }

    /**
     * @dataProvider combinations
     */
    public function test_router_client_options_carry_verify_ssl( bool $ssl, bool $verify ) : void {
        SettingsService::save_settings( MUMB_Settings::from_array( [
            'host'       => 'router.local',
            'username'   => 'admin',
            'password'   => 'secret',
            'ssl'        => $ssl,
            'verify_ssl' => $verify,
        ] ) );

        $rest_opts = MWP_REST::opts();
        $this->assertSame( $verify, $rest_opts['verify_ssl'] );
        $this->assertSame( $ssl, $rest_opts['ssl'] );

        $build      = new ReflectionMethod( MUMB_Queue::class, 'build_opts' );
        $build->setAccessible( true );
        $queue_opts = $build->invoke( null, MUMB_Settings::load() );
        $this->assertSame( $verify, $queue_opts['verify_ssl'] );
    }
}
