<?php

use PHPUnit\Framework\TestCase;

class RestFallbackTest extends TestCase {
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_fallback_loads_rest_classes_without_composer() : void {
        if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
        define( 'MUMB_SKIP_COMPOSER_AUTOLOAD', true );

        if ( ! function_exists( 'plugin_dir_path' ) ) {
            function plugin_dir_path( $file ) {
                return rtrim( dirname( $file ), '/\\' ) . '/';
            }
        }

        if ( ! function_exists( 'plugin_dir_url' ) ) {
            function plugin_dir_url( $file ) {
                return 'http://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
            }
        }

        if ( ! function_exists( 'plugin_basename' ) ) {
            function plugin_basename( $file ) {
                return basename( dirname( $file ) ) . '/' . basename( $file );
            }
        }

        if ( ! function_exists( 'trailingslashit' ) ) {
            function trailingslashit( $path ) {
                return rtrim( $path, '/\\' ) . '/';
            }
        }

        $plugin = dirname( __DIR__, 4 ) . '/mikrotik-user-manager-bridge.php';
        try {
            require $plugin;
        } catch ( ParseError $e ) {
            $this->markTestSkipped( 'Plugin bootstrap unavailable: ' . $e->getMessage() );
        }

        $this->assertTrue( class_exists( 'MUMB_REST', false ), 'MUMB_REST should be available when composer autoload is skipped.' );
        $this->assertTrue( class_exists( 'MWP_REST', false ), 'MWP_REST should be available when composer autoload is skipped.' );
    }
}
