<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUMB_Health {

	public static function init(): void {
		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		}
	}

	/**
	 * Collect health issues and warnings.
	 *
	 * @return array<int,array{type:string,message:string}>
	 */
	public static function issues(): array {
		$issues = array();

		// RouterOS client availability
		if ( ! class_exists( 'MUMB_RouterOS_API' ) ) {
			$issues[] = array(
				'type'    => 'error',
				'message' => __( 'MikroTik: RouterOS client class is missing. Please ensure the plugin files are intact and not blocked by a must-use plugin or autoloader.', 'mikrotik-user-manager-bridge' ),
			);
		}

		// REST routes present (best-effort check: class exists and action registered)
		if ( ! class_exists( 'MUMB_REST' ) ) {
			$issues[] = array(
				'type'    => 'warning',
				'message' => __( 'MikroTik: REST handler not loaded. Connect/Disconnect/Ping buttons may not work. Check if REST API is enabled.', 'mikrotik-user-manager-bridge' ),
			);
		}

		// Cron schedules (warn if missing)
		if ( function_exists( 'wp_next_scheduled' ) ) {
			$sync_scheduled = wp_next_scheduled( 'mumb_sync_cron' );
			if ( false === $sync_scheduled ) {
				$issues[] = array(
					'type'    => 'warning',
					'message' => __( 'MikroTik: Sync cron is not scheduled. Background sync will not run until activation or manual scheduling.', 'mikrotik-user-manager-bridge' ),
				);
			}
			$queue_scheduled = wp_next_scheduled( 'mumb_queue_cron' );
			if ( false === $queue_scheduled ) {
				$issues[] = array(
					'type'    => 'warning',
					'message' => __( 'MikroTik: Queue cron is not scheduled. Write jobs may not be processed automatically.', 'mikrotik-user-manager-bridge' ),
				);
			}
		}

		return $issues;
	}

	public static function admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$issues = self::issues();
		foreach ( $issues as $issue ) {
			$cls = 'notice';
			if ( $issue['type'] === 'error' ) {
				$cls .= ' notice-error';
			} elseif ( $issue['type'] === 'warning' ) {
				$cls .= ' notice-warning';
			} else {
				$cls .= ' notice-info';
			}
			echo '<div class="' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $issue['message'] ) . '</p></div>';
		}
	}
}

MUMB_Health::init();
