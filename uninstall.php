<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

// Options
$mumb_options = array(
	'mumb_settings',
	'mumb_connection_state',
	'mumb_keepalive_state',
	'mumb_profile_product_map',
	'mumb_product_profile_map', // name used by early versions
	'mumb_job_queue',
	'mumb_job_history',
	'mumb_sync_notice',
	'mumb_sync_state',
	'mumb_status_snapshot',
	'mumb_bootstrap_state',
	'mumb_master_mode',
	'mumb_branding',
	'mumb_affiliate_default_group',
	'mumb_profile_template',
	'mumb_profile_mode',
	'mumb_profile_default_server',
	'mumb_profile_token_ttl',
	'mumb_cli_token_hash',
	'mumb_cli_token_created',
	'mumb_cli_token_hint',
	'mumb_cli_token_alg',
	'mumb_db_version',
);
foreach ( $mumb_options as $mumb_option ) {
	delete_option( $mumb_option );
}

// Transients
delete_transient( 'mumb_server_info' );
delete_transient( 'mumb_servers_notices' );
delete_transient( 'mwp_rest_users' );
delete_transient( 'mumb_bootstrap_state' );
delete_transient( 'mumb_sync_notice' );

// The role this plugin added. Accounts keep their other roles.
remove_role( 'affiliate_partner' );

// Leave tables and per-user meta for audit/history; admins can drop them if desired.
// Clear cron
wp_clear_scheduled_hook( 'mumb_sync_cron' );
wp_clear_scheduled_hook( 'mumb_queue_cron' );
wp_clear_scheduled_hook( 'mumb_health_cron' );
