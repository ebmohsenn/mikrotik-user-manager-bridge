<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUMB_Security {

	protected static function key(): string {
		/** @var mixed $base */
		$base = defined( 'AUTH_KEY' ) ? AUTH_KEY : ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : wp_salt( 'auth' ) );
		if ( is_string( $base ) ) {
			$base_str = $base;
		} elseif ( is_scalar( $base ) ) {
			$base_str = (string) $base;
		} else {
			$base_str = '';
		}
		return hash( 'sha256', $base_str, true ); // 32 bytes
	}

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		$key    = self::key();
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return $plaintext; // fallback
		}
		return 'enc:' . base64_encode( $iv . $cipher );
	}

	public static function decrypt( string $maybe ): string {
		if ( '' === $maybe ) {
			return '';
		}
		if ( strncmp( $maybe, 'enc:', 4 ) !== 0 ) {
			return $maybe;
		}
		$blob = base64_decode( substr( $maybe, 4 ), true );
		if ( false === $blob || strlen( $blob ) < 17 ) {
			return '';
		}
		$iv     = substr( $blob, 0, 16 );
		$cipher = substr( $blob, 16 );
		$key    = self::key();
		$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}
}
