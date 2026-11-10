<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUMB_Logger {

	/**
	 * @param array<string, mixed> $meta
	 */
	public static function log( string $level, string $action, string $message = '', array $meta = array() ): void {
		try {
			/** @var wpdb|object|null $wpdb */
			$wpdb = $GLOBALS['wpdb'] ?? null;
			if ( ! ( $wpdb instanceof wpdb ) ) {
				return;
			}
			$prefix = $wpdb->prefix;
			$table  = $prefix . 'mikrotik_logs';
			$wpdb->insert(
				$table,
				array(
					'time'    => current_time( 'mysql' ),
					'level'   => $level,
					'action'  => $action,
					'message' => $message,
					'meta'    => empty( $meta ) ? null : wp_json_encode( $meta ),
				)
			);
		} catch ( \Throwable $e ) {
			/* noop */
		}
	}
}
