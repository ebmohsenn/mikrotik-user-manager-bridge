<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

// Options
delete_option( 'mumb_settings' );
delete_option( 'mumb_connection_state' );
delete_option( 'mumb_product_profile_map' );
delete_option( 'mumb_job_queue' );
delete_option( 'mumb_sync_notice' );

// Leave tables for audit/history; admins can drop if desired.
// Clear cron
wp_clear_scheduled_hook( 'mumb_sync_cron' );
wp_clear_scheduled_hook( 'mumb_queue_cron' );
