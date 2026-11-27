<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MUMB\Clients\RouterClientInterface;

if ( ! class_exists( '\\MUMB\\REST\\Users_Controller' ) && file_exists( __DIR__ . '/rest/class-rest-users.php' ) ) {
	require_once __DIR__ . '/rest/class-rest-users.php';
}
if ( ! class_exists( '\\MUMB\\REST\\Profiles_Controller' ) && file_exists( __DIR__ . '/rest/class-rest-profiles.php' ) ) {
	require_once __DIR__ . '/rest/class-rest-profiles.php';
}
if ( ! class_exists( '\\MUMB\\REST\\Affiliates_Controller' ) && file_exists( __DIR__ . '/rest/class-rest-affiliates.php' ) ) {
	require_once __DIR__ . '/rest/class-rest-affiliates.php';
}
if ( ! class_exists( '\\MUMB\\REST\\Settings_Controller' ) && file_exists( __DIR__ . '/rest/class-rest-settings.php' ) ) {
	require_once __DIR__ . '/rest/class-rest-settings.php';
}

use MUMB\REST\Affiliates_Controller;
use MUMB\REST\Profiles_Controller;
use MUMB\REST\Settings_Controller;
use MUMB\REST\Users_Controller;
use MUMB\Services\CapabilityService;
use MUMB\Services\SettingsService;

class MWP_REST {

	const CACHE_KEY_USERS = 'mwp_rest_users';
	const CACHE_TTL       = 30;

	public static function throttle( string $key, int $limit = 30, int $window = 60 ): bool {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		if ( '' === $ip ) {
			return false;
		}
		$hash = md5( $key . '|' . $ip );
			/** @var array{count:int,start:int}|false $bucket */
			$bucket = get_transient( 'mwp_throttle_' . $hash );
		if ( ! is_array( $bucket ) || time() - $bucket['start'] > $window ) {
				$bucket = array(
					'count' => 0,
					'start' => time(),
				);
		}
			++$bucket['count'];
			set_transient( 'mwp_throttle_' . $hash, $bucket, $window );
			return $bucket['count'] > max( 1, $limit );
	}
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		// Alias routes for existing functionality
		register_rest_route(
			'mwp/v1',
			'/connect',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'MUMB_REST', 'connect' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'MUMB_REST', 'disconnect' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'MUMB_REST', 'ping' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		Users_Controller::register_routes();
		Profiles_Controller::register_routes();
		Affiliates_Controller::register_routes();
		Settings_Controller::register_routes();

		// Groups CRUD
		register_rest_route(
			'mwp/v1',
			'/groups',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'groups_list' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/groups',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'groups_create' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/groups/(?P<id>[^/]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'groups_update' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/groups/(?P<id>[^/]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'groups_delete' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		// Groups: sync helper
		register_rest_route(
			'mwp/v1',
			'/groups/sync',
			array(
				'methods'             => 'POST',
				'callback'            => function ( WP_REST_Request $req ) {
					if ( ! current_user_can( 'manage_options' ) ) {
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => 'Unauthorized',
							),
							403
						);
					}
					try {
						$n = ( new MUMB_Sync_Service() )->sync_groups();
						// Return fresh mirror list so UI can update immediately
						global $wpdb;
						if ( ! ( $wpdb instanceof wpdb ) ) {
							return new WP_REST_Response(
								array(
									'success' => false,
									'message' => 'Database unavailable',
								),
								500
							);
						}
						/** @var string $p */
						/** @var string $p */
						$p = $wpdb->prefix;
						if ( '' === $p ) {
							return new WP_REST_Response(
								array(
									'success' => false,
									'message' => 'Database unavailable',
								),
								500
							);
						}
						$table = $p . 'mikrotik_groups';
						// Query database for groups - table name cannot be prepared with %s
						$rows_raw = $wpdb->get_results( "SELECT name FROM `{$table}` ORDER BY name ASC", ARRAY_A );
						$rows     = self::normalize_rows( $rows_raw );
						if ( class_exists( 'MUMB_Logger' ) ) {
							MUMB_Logger::log( 'info', 'groups_sync', 'Groups synced', array( 'count' => $n ) );
						}
						return array(
							'success' => true,
							'data'    => $rows,
						);
					} catch ( \Throwable $e ) {
						if ( class_exists( 'MUMB_Logger' ) ) {
							MUMB_Logger::log( 'error', 'groups_sync', $e->getMessage() );
						}
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => $e->getMessage(),
							),
							500
						);
					}
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Health summary
		register_rest_route(
			'mwp/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					$state    = SettingsService::get_connection_state();
					/** @var mixed $sync_raw */
					$sync_raw = get_option( 'mumb_sync_state', array() );
					$sync     = is_array( $sync_raw ) ? $sync_raw : array();
					try {
						$caps = ( new CapabilityService() )->get_capabilities();
					} catch ( \Throwable $e ) {
						$caps = array();
						if ( class_exists( 'MUMB_Logger' ) ) {
							MUMB_Logger::log( 'warning', 'capabilities', 'Health capability fetch failed', array( 'error' => $e->getMessage() ) );
						}
					}
					$settings   = MUMB_Settings::load();
					/** @var mixed $info_raw */
					$info_raw   = get_transient( 'mumb_server_info' );
					$info       = is_array( $info_raw ) ? $info_raw : array();
					$resource   = isset( $info['resource'] ) && is_array( $info['resource'] ) ? $info['resource'] : array();
					$identity   = isset( $info['identity'] ) && is_array( $info['identity'] ) ? $info['identity'] : array();
					/** @var mixed $latency */
					$latency    = $info['latency_ms'] ?? 0;
					$latency_ms = is_numeric( $latency ) ? (int) $latency : 0;
					return array(
						'success' => true,
						'data'    => array(
							'connected'    => ! empty( $state['connected'] ),
							'last_success' => isset( $state['last_success'] ) && is_string( $state['last_success'] ) ? $state['last_success'] : '',
							'last_error'   => isset( $state['last_error'] ) && is_string( $state['last_error'] ) ? $state['last_error'] : '',
							'last_sync'    => isset( $sync['last_sync'] ) && is_string( $sync['last_sync'] ) ? $sync['last_sync'] : '',
							'last_sync_ms' => isset( $sync['last_duration_ms'] ) && is_numeric( $sync['last_duration_ms'] ) ? (int) $sync['last_duration_ms'] : 0,
							'caps'         => $caps,
							'settings'     => array( 'mirror_authority' => $settings->mirror_authority ?? 'router' ),
							'server'       => array(
								'version'    => isset( $resource['version'] ) && is_string( $resource['version'] ) ? $resource['version'] : '',
								'identity'   => isset( $identity['name'] ) && is_string( $identity['name'] ) ? $identity['name'] : '',
								'latency_ms' => $latency_ms,
							),
						),
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					if ( ! current_user_can( 'manage_options' ) ) {
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => 'Unauthorized',
							),
							403
						);
					}
					$state    = SettingsService::get_connection_state();
					/** @var mixed $sync */
					$sync     = get_option( 'mumb_sync_state', array() );
					/** @var mixed $queue */
					$queue    = get_option( MUMB_Queue::OPTION_KEY, array() );
					$queue    = is_array( $queue ) ? $queue : array();
					$next_run = null;
					foreach ( $queue as $job ) {
						if ( ! is_array( $job ) ) {
							continue;
						}
						$nr = isset( $job['next_run'] ) ? (int) $job['next_run'] : 0;
						if ( $nr > 0 && ( null === $next_run || $nr < $next_run ) ) {
							$next_run = $nr;
						}
					}
					/** @var mixed $server_info */
					$server_info = get_transient( 'mumb_server_info' ) ?: array();
					return array(
						'success' => true,
						'data'    => array(
							'connection_state' => $state,
							'sync_state'       => $sync,
							'queue'            => array(
								'size'     => count( $queue ),
								'next_run' => $next_run ? gmdate( 'c', $next_run ) : null,
							),
							'server_info'      => $server_info,
						),
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/router/capabilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'router_capabilities' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Sync diff (dry-run) and apply push-from-db
		register_rest_route(
			'mwp/v1',
			'/sync/diff',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					try {
						// Ensure clean output buffer
						if ( ob_get_level() ) {
							ob_clean();
						}
						
						$svc = new MUMB_Sync_Service();
						$ref = new ReflectionClass( $svc );
						$m   = $ref->getMethod( 'get_router_client' );
						/** @psalm-suppress UnusedMethodCall */
						$m->setAccessible( true );
						/** @var RouterClientInterface|null $api */
						$api = $m->invoke( $svc );
						if ( ! $api ) {
							return new WP_REST_Response( array(
								'success' => false,
								'message' => 'No API connection available',
								'data'    => array(),
							), 200 );
						}
						/** @psalm-suppress InvalidGlobal */
						global $wpdb;
						if ( ! ( $wpdb instanceof wpdb ) ) {
							return new WP_REST_Response( array(
								'success' => false,
								'message' => 'Database unavailable',
								'data'    => array(),
							), 200 );
						}
						/** @var string $p */
						$p = $wpdb->prefix;
						if ( '' === $p ) {
							return new WP_REST_Response( array(
								'success' => true,
								'data'    => array(
									'diff'     => array(),
									'summary'  => array(),
									'users'    => array(),
									'groups'   => array(),
									'profiles' => array(),
									'notes'    => array(),
								),
							), 200 );
						}
						$out           = array(
							'create' => array(
								'groups'        => array(),
								'profiles'      => array(),
								'users'         => array(),
								'user_profiles' => array(),
							),
							'update' => array( 'users' => array() ),
						);
						$groups_table        = $p . 'mikrotik_groups';
						$profiles_table      = $p . 'mikrotik_profiles';
						$users_table         = $p . 'mikrotik_users';
						$user_profiles_table = $p . 'mikrotik_user_profiles';
						$log_anomaly   = static function ( string $action, mixed $response ): void {
							if ( ! class_exists( 'MUMB_Logger' ) ) {
								return;
							}
							if ( is_array( $response ) ) {
								$context = array(
									'status' => $response['status'] ?? null,
									'error'  => $response['error_message'] ?? null,
									'keys'   => array_keys( $response ),
								);
							} else {
								$context = array( 'response_type' => gettype( $response ) );
							}
							MUMB_Logger::log( 'warning', 'rest_sync_diff', $action, $context );
						};
						/** @var string|false $prepared_sql */
						$prepared_sql = $wpdb->prepare( 'SELECT name FROM `%s`', $groups_table );
						/** @var array<string>|null $db_groups_raw */
						$db_groups_raw = is_string( $prepared_sql ) ? $wpdb->get_col( $prepared_sql ) : null;
						$db_groups     = self::sanitize_string_list( $db_groups_raw );

						$live_g = $api->getGroups();
						/** @var array{status:string, data?:array<int, array<string, mixed>>, error_code?:string, error_message?:string} $live_g */
						$lg = array();
						if ( ! self::is_success( $live_g ) ) {
							$log_anomaly( 'getGroups', $live_g );
						}
						$live_group_rows = $live_g['data'] ?? array();
						if ( ! is_array( $live_group_rows ) ) {
							$log_anomaly( 'getGroups', $live_g );
							$live_group_rows = array();
						}
						foreach ( $live_group_rows as $row ) {
							if ( ! is_array( $row ) ) {
								continue;
							}
							$name_val = $row['name'] ?? null;
							if ( ! is_scalar( $name_val ) ) {
								continue;
							}
							$name = (string) $name_val;
							if ( '' === $name ) {
								continue;
							}
							$lg[ $name ] = true;
						}
						foreach ( $db_groups as $group_name ) {
							if ( empty( $lg[ $group_name ] ) ) {
								$out['create']['groups'][] = $group_name;
							}
						}

						/** @var string|false $prepared_sql */
						$prepared_sql = $wpdb->prepare( 'SELECT name FROM `%s`', $profiles_table );
						/** @var array<string>|null $db_profiles_raw */
						$db_profiles_raw = is_string( $prepared_sql ) ? $wpdb->get_col( $prepared_sql ) : null;
						$db_profiles     = self::sanitize_string_list( $db_profiles_raw );
						$live_p          = $api->getProfiles();
						/** @var array{status:string, data?:array<int, array<string, mixed>>, error_code?:string, error_message?:string} $live_p */
						$lp = array();
						if ( ! self::is_success( $live_p ) ) {
							$log_anomaly( 'getProfiles', $live_p );
						}
						$live_profile_rows = $live_p['data'] ?? array();
						if ( ! is_array( $live_profile_rows ) ) {
							$log_anomaly( 'getProfiles', $live_p );
							$live_profile_rows = array();
						}
						foreach ( $live_profile_rows as $row ) {
							if ( ! is_array( $row ) ) {
								continue;
							}
							/** @var mixed $name_val */
							$name_val = $row['name'] ?? null;
							if ( ! is_scalar( $name_val ) ) {
								continue;
							}
							$name = (string) $name_val;
							if ( '' === $name ) {
								continue;
							}
							$lp[ $name ] = true;
						}
						foreach ( $db_profiles as $profile_name ) {
							if ( empty( $lp[ $profile_name ] ) ) {
								$out['create']['profiles'][] = $profile_name;
							}
						}
						/** @var string|false $sql_users */
						$sql_users = $wpdb->prepare( 'SELECT username, comment, profile FROM `%s`', $users_table );
						/** @var array<array<string,mixed>>|null $db_users_raw */
						$db_users_raw = is_string( $sql_users ) ? $wpdb->get_results( $sql_users, ARRAY_A ) : array();
						$db_users     = is_array( $db_users_raw ) ? $db_users_raw : array();
						// For delete diff: build lowercase username sets for quick membership tests
						/** @var string|false $sql_usernames */
						$sql_usernames = $wpdb->prepare( 'SELECT username FROM `%s`', $users_table );
						/** @var array<string>|null $db_usernames_raw */
						$db_usernames_raw = is_string( $sql_usernames ) ? $wpdb->get_col( $sql_usernames ) : array();
						$db_usernames     = array_unique( array_map( 'strtolower', self::sanitize_string_list( $db_usernames_raw ) ) );
						$live_u           = $api->getUsers();
						/** @var array{status:string, data?:array<int, array<string, mixed>>, error_code?:string, error_message?:string} $live_u */
						$lu = array();
						if ( ! self::is_success( $live_u ) ) {
							$log_anomaly( 'getUsers', $live_u );
						}
						$live_user_rows = $live_u['data'] ?? array();
						if ( ! is_array( $live_user_rows ) ) {
							$log_anomaly( 'getUsers', $live_u );
							$live_user_rows = array();
						}
						foreach ( $live_user_rows as $row ) {
							if ( ! is_array( $row ) ) {
								continue;
							}
							$user_val = $row['username'] ?? ( $row['login'] ?? ( $row['name'] ?? '' ) );
							$username = is_scalar( $user_val ) ? (string) $user_val : '';
							if ( '' === $username ) {
								continue;
							}
							$lu[ strtolower( $username ) ] = $row;
						}
						foreach ( $db_users as $u ) {
							/** @var mixed $username_val */
							$username_val = $u['username'] ?? '';
							$username     = is_scalar( $username_val ) ? (string) $username_val : '';
							if ( '' === $username ) {
								continue;
							}
							$key = strtolower( $username );
							if ( ! isset( $lu[ $key ] ) ) {
								$out['create']['users'][] = $username;
								continue;
							}
							$row = $lu[ $key ];
							if ( ! is_array( $row ) ) {
								continue;
							}
							$chg             = array();
							/** @var mixed $row_comment_val */
							$row_comment_val = $row['comment'] ?? '';
							$row_comment     = is_scalar( $row_comment_val ) ? (string) $row_comment_val : '';
							/** @var mixed $db_comment_val */
							$db_comment_val  = $u['comment'] ?? '';
							$db_comment      = is_scalar( $db_comment_val ) ? (string) $db_comment_val : '';
							if ( $row_comment !== $db_comment ) {
								$chg[] = 'comment';
							}
							/** @var mixed $gdb_val */
							$gdb_val   = $u['profile'] ?? '';
							$gdb       = is_scalar( $gdb_val ) ? (string) $gdb_val : '';
							/** @var mixed $glive_val */
							$glive_val = $row['group'] ?? '';
							$glive     = is_scalar( $glive_val ) ? (string) $glive_val : '';
							if ( '' !== $gdb && $gdb !== $glive ) {
								$chg[] = 'group';
							}
							if ( $chg ) {
								$out['update']['users'][] = array(
									'username' => $username,
									'fields'   => $chg,
								);
							}
						}
						/** @var string|false $sql_pairs */
						$sql_pairs = $wpdb->prepare( 'SELECT username, profile FROM `%s`', $user_profiles_table );
						/** @var array<array<string,mixed>>|null $db_pairs_raw */
						$db_pairs_raw = is_string( $sql_pairs ) ? $wpdb->get_results( $sql_pairs, ARRAY_A ) : array();
						$db_pairs     = is_array( $db_pairs_raw ) ? $db_pairs_raw : array();
						$live_up      = $api->getUserProfiles();
						/** @var array{status:string, data?:array<int, array<string, mixed>>, error_code?:string, error_message?:string} $live_up */
						$lup = array();
						if ( ! self::is_success( $live_up ) ) {
							$log_anomaly( 'getUserProfiles', $live_up );
						}
						$live_profile_rows = $live_up['data'] ?? array();
						if ( ! is_array( $live_profile_rows ) ) {
							$log_anomaly( 'getUserProfiles', $live_up );
							$live_profile_rows = array();
						}
						foreach ( $live_profile_rows as $row ) {
							if ( ! is_array( $row ) ) {
								continue;
							}
							/** @var mixed $user_val */
							$user_val    = $row['user'] ?? ( $row['users'] ?? ( $row['name'] ?? '' ) );
							$user        = is_scalar( $user_val ) ? (string) $user_val : '';
							$profile_val = $row['profile'] ?? '';
							$profile     = is_scalar( $profile_val ) ? (string) $profile_val : '';
							if ( '' === $user || '' === $profile ) {
								continue;
							}
							$lup[ strtolower( $user ) . '|' . $profile ] = true;
						}
						foreach ( $db_pairs as $r ) {
							/** @var mixed $user_val */
							$user_val    = $r['username'] ?? null;
							/** @var mixed $profile_val */
							$profile_val = $r['profile'] ?? null;
							if ( ! is_scalar( $user_val ) || ! is_scalar( $profile_val ) ) {
								continue;
							}
							$user    = (string) $user_val;
							$profile = (string) $profile_val;
							if ( '' === $user || '' === $profile ) {
								continue;
							}
							$k = strtolower( $user ) . '|' . $profile;
							if ( empty( $lup[ $k ] ) ) {
								$out['create']['user_profiles'][] = array(
									'username' => $user,
									'profile'  => $profile,
								);
							}
						}
						// Deletes (router extras)
						$out['delete'] = array(
							'groups'        => array(),
							'profiles'      => array(),
							'users'         => array(),
							'user_profiles' => array(),
						);
						foreach ( array_keys( $lg ) as $name ) {
							if ( ! in_array( $name, $db_groups, true ) ) {
								$out['delete']['groups'][] = $name;
							}
						}
						foreach ( array_keys( $lp ) as $name ) {
							if ( ! in_array( $name, $db_profiles, true ) ) {
								$out['delete']['profiles'][] = $name;
							}
						}
						// Distinct usernames present in user_profiles (lowercased)
						/** @var string|false $prepared_sql */
						$prepared_sql = $wpdb->prepare( 'SELECT DISTINCT username FROM `%s`', $user_profiles_table );
						/** @var array<string>|null $db_up_raw */
						$db_up_raw = is_string( $prepared_sql ) ? $wpdb->get_col( $prepared_sql ) : null;
						$db_up     = array_unique( array_map( 'strtolower', self::sanitize_string_list( $db_up_raw ) ) );
						foreach ( array_keys( $lu ) as $uname ) {
							if ( ! in_array( $uname, $db_usernames, true ) && ! in_array( $uname, $db_up, true ) ) {
								$out['delete']['users'][] = $uname;
							}
						}
						foreach ( $lup as $key => $_ ) {
							$parts  = explode( '|', $key, 2 );
							$u      = isset( $parts[0] ) ? $parts[0] : '';
							$pr     = isset( $parts[1] ) ? $parts[1] : '';
							$k      = strtolower( $u ) . '|' . $pr;
							$exists = false;
							foreach ( $db_pairs as $r ) {
								$rr_user_val    = $r['username'] ?? null;
								$rr_profile_val = $r['profile'] ?? null;
								if ( ! is_scalar( $rr_user_val ) || ! is_scalar( $rr_profile_val ) ) {
									continue;
								}
								$rr_user    = (string) $rr_user_val;
								$rr_profile = (string) $rr_profile_val;
								if ( '' === $rr_user || '' === $rr_profile ) {
									continue;
								}
								$kk = strtolower( $rr_user ) . '|' . $rr_profile;
								if ( $kk === $k ) {
									$exists = true;
									break;
								}
							}
							if ( ! $exists ) {
								$out['delete']['user_profiles'][] = array(
									'username' => $u,
									'profile'  => $pr,
								);
							}
						}
						return new WP_REST_Response( array(
							'success' => true,
							'data'    => $out,
						), 200 );
					} catch ( \Throwable $e ) {
						// Log the error
						if ( class_exists( 'MUMB_Logger' ) ) {
							MUMB_Logger::log( 'error', 'rest_sync_diff', 'Exception caught', array(
								'message' => $e->getMessage(),
								'file'    => $e->getFile(),
								'line'    => $e->getLine(),
							) );
						}
					
						// Return error response
						return new WP_REST_Response( array(
							'success' => false,
							'message' => 'Internal error during sync diff operation',
							'data'    => array(),
						), 500 );
					}
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/sync/apply',
			array(
				'methods'             => 'POST',
				'callback'            => function ( WP_REST_Request $req ) {
					try {
						$destructive = (bool) $req->get_param( 'destructive' );
						$scope       = (array) $req->get_param( 'delete_scope' );
						$list        = (array) $req->get_param( 'delete_list' );
						( new MUMB_Sync_Service() )->push_from_db( $destructive, $scope, $list );
						return array( 'success' => true );
					} catch ( \Throwable $e ) {
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => $e->getMessage(),
							),
							500
						);
					}
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Routers CRUD
		register_rest_route(
			'mwp/v1',
			'/routers',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'routers_list' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/routers',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'routers_create' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/routers/(?P<id>[^/]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'routers_update' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/routers/(?P<id>[^/]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'routers_delete' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// User Profiles (assignments) — read from mirrored DB + sync
		$user_profiles_callback = function ( WP_REST_Request $req ) {
			$live = (bool) $req->get_param( 'live' );
			if ( $live ) {
				// Return live rows from RouterOS including state and end-time
				$api = MWP_REST::build_api();
				if ( ! $api ) {
					return new WP_REST_Response(
						array(
							'success' => false,
							'message' => 'Not configured',
						),
						400
					);
				}
				$r = $api->getUserProfiles();
				if ( ! self::is_success( $r ) ) {
					$msg = self::normalize_error_message( $r, 'Failed' );
					return new WP_REST_Response(
						array(
							'success' => false,
							'message' => $msg,
						),
						500
					);
				}
				$out       = array();
				$live_rows = $r['data'] ?? array();
				if ( ! is_array( $live_rows ) ) {
					$live_rows = array();
				}
				$now = current_time( 'mysql' );
				foreach ( $live_rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					/** @var mixed $user_val */
					$user_val    = $row['user'] ?? ( $row['users'] ?? ( $row['name'] ?? '' ) );
					$username    = is_scalar( $user_val ) ? (string) $user_val : '';
					/** @var mixed $profile_val */
					$profile_val = $row['profile'] ?? '';
					$profile     = is_scalar( $profile_val ) ? (string) $profile_val : '';
					if ( '' === $username || '' === $profile ) {
						continue;
					}
					/** @var mixed $state_val */
					$state_val = $row['state'] ?? '';
					$state     = is_scalar( $state_val ) ? (string) $state_val : '';
					/** @var mixed $end_val */
					$end_val   = $row['end-time'] ?? '';
					$end       = is_scalar( $end_val ) ? (string) $end_val : '';
					$out[]     = array(
						'username'   => $username,
						'profile'    => $profile,
						'state'      => $state,
						'end_time'   => $end,
						'updated_at' => $now,
						'expires_at' => $end,
					);
				}
				return array(
					'success' => true,
					'data'    => $out,
				);
			}
			// Default: from mirror DB (dedup)
			global $wpdb;
			if ( ! ( $wpdb instanceof wpdb ) ) {
				return array(
					'success' => false,
					'message' => 'Database unavailable',
				);
			}
			/** @var string $p */
			$p = $wpdb->prefix;
			if ( '' === $p ) {
				return array(
					'success' => false,
					'message' => 'Database unavailable',
				);
			}
			$user_profiles_table = $p . 'mikrotik_user_profiles';
			/** @var string $query */
			$query               = $wpdb->prepare(
				'SELECT username, profile, MAX(updated_at) AS updated_at, MAX(expires_at) AS expires_at FROM %i GROUP BY username, profile ORDER BY updated_at DESC LIMIT 500',
				$user_profiles_table
			);
			$rows_raw            = $wpdb->get_results( $query, ARRAY_A );
			if ( ! is_array( $rows_raw ) ) {
				$rows_raw = array();
			}
			$rows = self::normalize_rows( $rows_raw );
			return array(
				'success' => true,
				'data'    => $rows,
			);
		};
		
		register_rest_route(
			'mwp/v1',
			'/user-profiles',
			array(
				'methods'             => 'GET',
				'callback'            => $user_profiles_callback,
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		
		// Alias route for backwards compatibility with React app
		register_rest_route(
			'mwp/v1',
			'/users/profiles/live',
			array(
				'methods'             => 'GET',
				'callback'            => function ( WP_REST_Request $req ) use ( $user_profiles_callback ) {
					// Force live=1 parameter
					$req->set_param( 'live', true );
					return $user_profiles_callback( $req );
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		
		register_rest_route(
			'mwp/v1',
			'/user-profiles',
			array(
				'methods'             => 'POST',
				'callback'            => function ( WP_REST_Request $req ) {
					if ( ! current_user_can( 'manage_options' ) ) {
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => 'Unauthorized',
							),
							403
						);
					}
					$username = sanitize_text_field( (string) $req->get_param( 'username' ) );
					$profile  = sanitize_text_field( (string) $req->get_param( 'profile' ) );
					if ( '' === $username || '' === $profile ) {
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => 'username and profile are required',
							),
							400
						);
					}
					try {
						// Prefer synchronous assign to ensure immediate effect
						$api = MWP_REST::build_api();
						if ( ! $api ) {
							return new WP_REST_Response(
								array(
									'success' => false,
									'message' => 'Not configured',
								),
								400
							);
						}
						$res = $api->assign_profile( $username, $profile );
						if ( ! self::is_success( $res ) ) {
							$msg = self::normalize_error_message( $res, 'Failed to assign profile' );
							return new WP_REST_Response(
								array(
									'success' => false,
									'message' => $msg,
								),
								500
							);
						}
						// Refresh mirror so the UI reflects latest
						( new MUMB_Sync_Service() )->sync_user_profiles();
						return array( 'success' => true );
					} catch ( \Throwable $e ) {
						if ( class_exists( 'MUMB_Logger' ) ) {
							MUMB_Logger::log(
								'error',
								'rest_user_profiles_add',
								$e->getMessage(),
								array(
									'user'    => $username,
									'profile' => $profile,
								)
							);
						}
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => $e->getMessage(),
							),
							500
						);
					}
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/user-profiles/sync',
			array(
				'methods'             => 'POST',
				'callback'            => function ( WP_REST_Request $req ) {
					if ( ! current_user_can( 'manage_options' ) ) {
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => 'Unauthorized',
							),
							403
						);
					}
					$svc = new MUMB_Sync_Service();
					$n   = $svc->sync_user_profiles();
					global $wpdb;
					if ( ! ( $wpdb instanceof wpdb ) ) {
						return array(
							'success' => false,
							'message' => 'Database unavailable',
						);
					}
					/** @var string $p */
					$p = $wpdb->prefix;
					if ( '' === $p ) {
						return array(
							'success' => false,
							'message' => 'Database unavailable',
						);
					}
					$user_profiles_table = $p . 'mikrotik_user_profiles';
					/** @var string|false $sql_profiles */
					$sql_profiles = $wpdb->prepare( 'SELECT username, profile, expires_at, updated_at FROM `%s` ORDER BY updated_at DESC LIMIT 500', $user_profiles_table );
					$rows_raw     = is_string( $sql_profiles ) ? $wpdb->get_results( $sql_profiles, ARRAY_A ) : array();
					if ( ! is_array( $rows_raw ) ) {
						$rows_raw = array();
					}
					$rows = self::normalize_rows( $rows_raw );
					return array(
						'success' => true,
						'data'    => $rows,
						'count'   => $n,
					);
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Remove user-profile assignment
		register_rest_route(
			'mwp/v1',
			'/user-profiles',
			array(
				'methods'             => 'DELETE',
				'callback'            => function ( WP_REST_Request $req ) {
					if ( ! current_user_can( 'manage_options' ) ) {
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => 'Unauthorized',
							),
							403
						);
					}
					$username = sanitize_text_field( (string) $req->get_param( 'username' ) );
					$profile  = sanitize_text_field( (string) $req->get_param( 'profile' ) );
					if ( '' === $username || '' === $profile ) {
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => 'username and profile are required',
							),
							400
						);
					}
					try {
						$api = MWP_REST::build_api();
						if ( ! $api ) {
							return new WP_REST_Response(
								array(
									'success' => false,
									'message' => 'Not configured',
								),
								400
							);
						}
						$res = $api->remove_user_profile( $username, $profile );
						if ( ! self::is_success( $res ) ) {
							$msg = self::normalize_error_message( $res, 'Failed to remove profile' );
							return new WP_REST_Response(
								array(
									'success' => false,
									'message' => $msg,
								),
								500
							);
						}
						( new MUMB_Sync_Service() )->sync_user_profiles();
						return array( 'success' => true );
					} catch ( \Throwable $e ) {
						if ( class_exists( 'MUMB_Logger' ) ) {
							MUMB_Logger::log(
								'error',
								'rest_user_profiles_remove',
								$e->getMessage(),
								array(
									'user'    => $username,
									'profile' => $profile,
								)
							);
						}
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => $e->getMessage(),
							),
							500
						);
					}
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Test UM REST connectivity
		register_rest_route(
			'mwp/v1',
			'/umrest/test',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'umrest_test' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Self-service: change current user's location (assign profile)
		register_rest_route(
			'mwp/v1',
			'/self/location',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'self_set_location' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/self/group',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'self_set_group' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		// Self-service: change current user's password
		register_rest_route(
			'mwp/v1',
			'/self/password',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'self_set_password' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/cli/token',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'cli_token_get' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/cli/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cli_token_regenerate' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mwp/v1',
			'/affiliates',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'affiliates_list' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ) || current_user_can( 'manage_affiliates' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/affiliates',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'affiliates_save' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ) || current_user_can( 'manage_affiliates' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/affiliates/(?P<id>\\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'affiliates_save' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ) || current_user_can( 'manage_affiliates' );
				},
			)
		);
		register_rest_route(
			'mwp/v1',
			'/affiliates/(?P<id>\\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'affiliates_delete' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ) || current_user_can( 'manage_affiliates' );
				},
			)
		);

		// Logs: list
		register_rest_route(
			'mwp/v1',
			'/logs',
			array(
				'methods'             => 'GET',
				'callback'            => function ( WP_REST_Request $req ) {
					if ( ! current_user_can( 'manage_options' ) ) {
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => 'Unauthorized',
							),
							403
						);
					}
						global $wpdb;
						/** @var string $p */
						$p = $wpdb->prefix;
					if ( '' === $p ) {
						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => 'Database unavailable',
							),
							500
						);
					}
						$logs_table = $p . 'mikrotik_logs';
					$level  = sanitize_text_field( (string) $req->get_param( 'level' ) );
					$action = sanitize_text_field( (string) $req->get_param( 'action' ) );
					$s      = sanitize_text_field( (string) $req->get_param( 's' ) );
					$where  = array();
					$params = array();
					if ( $level ) {
						$where[]  = 'level = %s';
						$params[] = $level;
					}
					if ( $action ) {
						$where[]  = 'action = %s';
						$params[] = $action;
					}
					if ( $s ) {
						$where[]  = 'message LIKE %s';
						$params[] = '%' . $wpdb->esc_like( $s ) . '%';
					}
					$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';
					$rows = array();
					if ( ! empty( $params ) ) {
						/** @var string|false $base_sql */
						$base_sql = $wpdb->prepare( 'SELECT id,time,level,action,message FROM %s', $logs_table );
						if ( is_string( $base_sql ) ) {
							$sql = $base_sql . ' ' . $where_sql . ' ORDER BY time DESC LIMIT 200';
							$sql = $wpdb->prepare( $sql, $params );
							if ( is_string( $sql ) ) {
								$rows = self::normalize_rows( $wpdb->get_results( $sql, ARRAY_A ) );
							}
						}
					} else {
						/** @var string|false $sql_rows */
						$sql_rows = $wpdb->prepare( 'SELECT id,time,level,action,message FROM %s %s ORDER BY time DESC LIMIT 200', $logs_table, $where_sql );
						$rows     = is_string( $sql_rows ) ? self::normalize_rows( $wpdb->get_results( $sql_rows, ARRAY_A ) ) : array();
					}
						return array(
							'success' => true,
							'data'    => $rows,
						);
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	// --- Handlers ---
	protected static function settings(): MUMB_Settings {
		return MUMB_Settings::load();
	}

	/**
	 * @return array{}|array{
	 *     host: string,
	 *     port: int,
	 *     username: string,
	 *     password: string,
	 *     ssl: bool,
	 *     api_timeout: int,
	 *     retry_max?: int,
	 *     retry_base_ms?: int,
	 *     retry_jitter_ms?: int,
	 *     um_rest?: bool,
	 *     um_rest_base?: string
	 * }
	 */
	public static function opts(): array {
		$settings = self::settings();
		if ( ! $settings->is_configured() ) {
			return array();
		}

		$host     = $settings->host;
		$username = $settings->username;
		$password = $settings->decrypted_password();
		if ( '' === $host || '' === $username || '' === $password ) {
			return array();
		}

		$payload                    = array(
			'host'        => $host,
			'port'        => $settings->port,
			'username'    => $username,
			'password'    => $password,
			'ssl'         => $settings->ssl,
			'api_timeout' => $settings->api_timeout,
		);
		$payload['retry_max']       = $settings->retry_max;
		$payload['retry_base_ms']   = $settings->retry_base_ms;
		$payload['retry_jitter_ms'] = $settings->retry_jitter_ms;
		$payload['um_rest']         = $settings->um_rest;
		if ( isset( $settings->um_rest_base ) && $settings->um_rest_base ) {
			$payload['um_rest_base'] = $settings->um_rest_base;
		}
		return $payload;
	}

	public static function build_api(): ?MikroTik_API {
		$opts = self::opts();
		if ( empty( $opts ) ) {
			return null;
		}
		try {
			return new MikroTik_API( $opts );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'error', 'rest_api_build', $e->getMessage() );
			}
			return null;
		}
	}



	/**
	 * @param array{success: bool, data: array<int, array<string, mixed>>} $payload
	 * @return array{success: bool, data: array<int, array<string, mixed>>, meta: array<string, mixed>}
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, data: array<int, array<string, mixed>>, meta?: array<string, mixed>}|WP_REST_Response
	 */

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array<string, mixed>|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array<string, mixed>|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */



	// Profiles
	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, data: array<int, array<string, mixed>>}|WP_REST_Response
	 */

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */


	// Groups
	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, data?: array<int, array<string, mixed>>, message?: string}|WP_REST_Response
	 */
	public static function groups_list( WP_REST_Request $req ): array|WP_REST_Response {
			$api = self::build_api();
		if ( $api ) {
			try {
				/** @var array{status:string,data?:array<int,array<string,mixed>>} $r */
					$r = $api->getGroups();
				if ( $r['status'] === 'ok' ) {
					$rows = $r['data'] ?? array();
					return array(
						'success' => true,
						'data'    => self::normalize_rows( $rows ),
					);
				}
			} catch ( \Throwable $e ) {
				if ( class_exists( 'MUMB_Logger' ) ) {
					MUMB_Logger::log( 'warning', 'groups_list', $e->getMessage() );
				}
			}
		}

		global $wpdb;
		if ( ! ( $wpdb instanceof wpdb ) ) {
			return array(
				'success' => false,
				'message' => 'Database unavailable',
			);
		}
		/** @var string $p */
		$p = $wpdb->prefix;
		if ( '' === $p ) {
			return array(
				'success' => false,
				'message' => 'Database prefix unavailable',
			);
		}
		$groups_table = $p . 'mikrotik_groups';
		// Query database for groups - table name cannot be prepared with %s
		$rows_raw     = $wpdb->get_results( "SELECT name FROM `{$groups_table}` ORDER BY name ASC", ARRAY_A );
		$rows_list    = self::normalize_rows( $rows_raw );
		if ( ! empty( $rows_list ) ) {
			return array(
				'success' => true,
				'data'    => $rows_list,
			);
		}
		try {
			( new MUMB_Sync_Service() )->sync_groups();
		} catch ( \Throwable $e ) {
			/* ignore */
		}
		/** @var string $p */
		$p = $wpdb->prefix;
		if ( '' === $p ) {
			return array(
				'success' => false,
				'data'    => array(),
			);
		}
		$groups_table = $p . 'mikrotik_groups';
		// Query database for groups after sync - table name cannot be prepared with %s
		$rows_raw     = $wpdb->get_results( "SELECT name FROM `{$groups_table}` ORDER BY name ASC", ARRAY_A );
		$rows_list    = self::normalize_rows( $rows_raw );
		return array(
			'success' => true,
			'data'    => $rows_list,
		);
	}
	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */
	public static function groups_create( WP_REST_Request $req ): array|WP_REST_Response {
		$api = self::build_api();
		if ( ! $api ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Not configured',
				),
				400
			);
		}
		$payload = self::sanitize_assoc_payload( $req->get_json_params() );
		$res     = $api->add_group( $payload );
		if ( ! self::is_success( $res ) ) {
			$msg = self::normalize_error_message( $res, 'Failed to create group' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $msg,
				),
				500
			);
		}
		return array( 'success' => true );
	}
	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */
	public static function groups_update( WP_REST_Request $req ): array|WP_REST_Response {
		$api = self::build_api();
		if ( ! $api ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Not configured',
				),
				400
			);
		}
		/** @var mixed $id_param */ $id_param = $req->get_param( 'id' );
		$id                                   = sanitize_text_field( is_scalar( $id_param ) ? (string) $id_param : '' );
		$payload                              = self::sanitize_assoc_payload( $req->get_json_params() );
		$res                                  = $api->update_group( $id, $payload );
		if ( ! self::is_success( $res ) ) {
			$msg = self::normalize_error_message( $res, 'Failed to update group' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $msg,
				),
				500
			);
		}
		return array( 'success' => true );
	}
	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */
	public static function groups_delete( WP_REST_Request $req ): array|WP_REST_Response {
		$api = self::build_api();
		if ( ! $api ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Not configured',
				),
				400
			);
		}
		/** @var mixed $id_param */ $id_param = $req->get_param( 'id' );
		$id                                   = sanitize_text_field( is_scalar( $id_param ) ? (string) $id_param : '' );
		$res                                  = $api->delete_group( $id );
		if ( ! self::is_success( $res ) ) {
			$msg = self::normalize_error_message( $res, 'Failed to delete group' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $msg,
				),
				500
			);
		}
		return array( 'success' => true );
	}

	// Routers
	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, data: array<int, array<string, mixed>>}|WP_REST_Response
	 */
	public static function routers_list( WP_REST_Request $req ): array|WP_REST_Response {
		$api = self::build_api();
		if ( ! $api ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Not configured',
				),
				400
			);
		}
		$res = $api->getRouters();
		if ( ! self::is_success( $res ) ) {
			$msg = self::normalize_error_message( $res, 'Failed to list routers' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $msg,
				),
				500
			);
		}
		$data = array();
		$rows = $res['data'] ?? array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( is_array( $row ) ) {
					$data[] = $row;
				}
			}
		}
		return array(
			'success' => true,
			'data'    => $data,
		);
	}
	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */
	public static function routers_create( WP_REST_Request $req ): array|WP_REST_Response {
		$api = self::build_api();
		if ( ! $api ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Not configured',
				),
				400
			);
		}
		$payload = self::sanitize_assoc_payload( $req->get_json_params() );
		$res     = $api->create_router( $payload );
		if ( ! self::is_success( $res ) ) {
			$msg = self::normalize_error_message( $res, 'Failed to create router' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $msg,
				),
				500
			);
		}
		return array( 'success' => true );
	}
	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */
	public static function routers_update( WP_REST_Request $req ): array|WP_REST_Response {
		$api = self::build_api();
		if ( ! $api ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Not configured',
				),
				400
			);
		}
		/** @var mixed $id_param */ $id_param = $req->get_param( 'id' );
		$id                                   = sanitize_text_field( is_scalar( $id_param ) ? (string) $id_param : '' );
		$payload                              = self::sanitize_assoc_payload( $req->get_json_params() );
		$res                                  = $api->update_router( $id, $payload );
		if ( ! self::is_success( $res ) ) {
			$msg = self::normalize_error_message( $res, 'Failed to update router' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $msg,
				),
				500
			);
		}
		return array( 'success' => true );
	}
	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */
	public static function routers_delete( WP_REST_Request $req ): array|WP_REST_Response {
		$api = self::build_api();
		if ( ! $api ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Not configured',
				),
				400
			);
		}
		/** @var mixed $id_param */ $id_param = $req->get_param( 'id' );
		$id                                   = sanitize_text_field( is_scalar( $id_param ) ? (string) $id_param : '' );
		$res                                  = $api->delete_router( $id );
		if ( ! self::is_success( $res ) ) {
			$msg = self::normalize_error_message( $res, 'Failed to delete router' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $msg,
				),
				500
			);
		}
		return array( 'success' => true );
	}

	/**
	 * @param mixed $values
	 * @return array<int, string>
	 */
	/**
	 * @param mixed $values
	 * @return array<string>
	 */
	private static function sanitize_string_list( $values ): array {
		$result = array();
		if ( ! is_array( $values ) ) {
			return $result;
		}
		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$string = (string) $value;
			if ( '' === $string ) {
				continue;
			}
			$result[] = $string;
		}
		return $result;
	}

	/**
	 * @param mixed $data
	 * @return array<string, bool|float|int|string>
	 * @phpstan-return array<string, bool|float|int|string>
	 */
	public static function sanitize_assoc_payload( $data ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}
			$out = array();
			/** @var mixed $value */
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( null === $value ) {
				continue;
			}
			if ( is_scalar( $value ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $response
	 */
	public static function normalize_error_message( array $response, string $default ): string {
			/** @var mixed $msg */
			$msg = $response['error_message'] ?? ( $response['message'] ?? $default );
		return is_string( $msg ) && '' !== $msg ? $msg : $default;
	}

	/**
	 * @param array<string, mixed> $response
	 */
	public static function is_success( array $response ): bool {
		return isset( $response['status'] ) && $response['status'] === 'ok';
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function connection_state(): array {
		return SettingsService::get_connection_state();
	}

	/**
	 * @param mixed $rows_raw
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_rows( $rows_raw ): array {
		$result = array();
		if ( ! is_array( $rows_raw ) ) {
			return $result;
		}
		foreach ( $rows_raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			/** @var array<string, mixed> $row */
			/** @var array<string, mixed> $normalized */
			$normalized = array();
			/** @psalm-suppress MixedAssignment */
			foreach ( $row as $col_key => $col_value ) {
				if ( ! is_string( $col_key ) ) {
					continue;
				}
				if ( is_scalar( $col_value ) || null === $col_value ) {
					// Assign to a local var first to help static analyzers
					$val                    = $col_value;
					$normalized[ $col_key ] = $val;
				}
			}
			$result[] = $normalized;
		}
		return $result;
	}

	/**
	 * @param array<int, scalar> $params
	 */
	private static function safe_get_prepared_var_int( string $sql, array $params ): int {
		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return 0;
		}
		// @phpstan-ignore-next-line dynamic query assembled from validated table names
		$q = $wpdb->prepare( $sql, $params );
		if ( ! is_string( $q ) ) {
			return 0;
		}
		/** @var mixed $value */
		$value = $wpdb->get_var( $q );
		return is_numeric( $value ) ? (int) $value : 0;
	}


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, message: string, profiles?: int}|WP_REST_Response
	 */
	public static function umrest_test( WP_REST_Request $req ): array|WP_REST_Response {
		$settings = self::settings();
		if ( ! $settings->um_rest || $settings->um_rest_base === '' ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'UM REST is not configured. Enable it in Settings.',
				),
				400
			);
		}
		try {
			$opts = self::opts();
			if ( empty( $opts ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Not configured',
					),
					400
				);
			}
			$api   = new MikroTik_API( $opts );
				$r = $api->getProfiles();
			if ( $r['status'] === 'ok' ) {
				$count = 0;
				if ( isset( $r['data'] ) ) {
					$data = $r['data'];
					if ( is_array( $data ) ) {
						$count = count( $data );
					}
				}
				return array(
					'success'  => true,
					'message'  => 'UM REST reachable',
					'profiles' => $count,
				);
			}
			$msg = self::normalize_error_message( $r, 'UM REST test failed' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $msg,
				),
				500
			);
		} catch ( \Throwable $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */
	public static function self_set_location( WP_REST_Request $req ): array|WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Unauthorized',
				),
				401
			);
		}
			/** @var mixed $profile_param */
			$profile_param = $req->get_param( 'profile' );
		$profile           = sanitize_text_field( is_scalar( $profile_param ) ? (string) $profile_param : '' );
		if ( '' === $profile ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Missing profile',
				),
				400
			);
		}
		$req->set_param( 'group', $profile );
		return self::self_set_group( $req );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */
	public static function self_set_group( WP_REST_Request $req ): array|WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Unauthorized',
				),
				401
			);
		}
			/** @var mixed $group_param */
			$group_param = $req->get_param( 'group' );
		$group           = sanitize_text_field( is_scalar( $group_param ) ? (string) $group_param : '' );
		if ( '' === $group ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Missing group',
				),
				400
			);
		}
			global $wpdb;
		/** @var string $p */
		$p = $wpdb->prefix;
		if ( '' === $p ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Database unavailable',
				),
				500
			);
		}
		$groups_table = $p . 'mikrotik_groups';
		$group_exists = self::safe_get_prepared_var_int( 'SELECT COUNT(*) FROM ' . $groups_table . ' WHERE name=%s', array( $group ) );
		if ( ! $group_exists ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Group not found',
				),
				404
			);
		}
		$user     = wp_get_current_user();
		$username = $user->exists() ? $user->user_login : '';
		if ( '' === $username ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Cannot resolve current user',
				),
				400
			);
		}
		$api = self::build_api();
		if ( ! $api ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Router not configured',
				),
				500
			);
		}
		try {
			$res = $api->update_user( $username, array( 'group' => $group ) );
			if ( ! self::is_success( $res ) ) {
				$msg = self::normalize_error_message( $res, 'Failed to update group' );
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => $msg,
					),
					500
				);
			}
			$wp_user_id = 0;
			if ( class_exists( 'MUMB_WP_Users' ) ) {
				$wp_user_id = MUMB_WP_Users::ensure_user( $username, array( 'group' => $group ) );
			}
			if ( $wp_user_id > 0 ) {
				update_user_meta( $wp_user_id, 'mumb_affiliate_owner', get_current_user_id() );
			} else {
				$wp_user = get_user_by( 'login', $username );
				if ( $wp_user instanceof WP_User ) {
					update_user_meta( $wp_user->ID, 'mumb_affiliate_owner', get_current_user_id() );
				}
			}
			( new MUMB_Sync_Service() )->sync_users();
			Users_Controller::flush_cache();
			return array( 'success' => true );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log(
					'error',
					'self_set_group',
					$e->getMessage(),
					array(
						'user'  => $username,
						'group' => $group,
					)
				);
			}
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */
	public static function self_set_password( WP_REST_Request $req ): array|WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Unauthorized',
				),
				401
			);
		}

		/** @var mixed $current_password_param */
		$current_password_param = $req->get_param( 'current_password' );
		$current_password = is_scalar( $current_password_param ) ? (string) $current_password_param : '';
		
		/** @var mixed $new_password_param */
		$new_password_param = $req->get_param( 'new_password' );
		$new_password = is_scalar( $new_password_param ) ? (string) $new_password_param : '';

		if ( '' === $current_password || '' === $new_password ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Missing current_password or new_password',
				),
				400
			);
		}

		if ( strlen( $new_password ) < 8 ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Password must be at least 8 characters long',
				),
				400
			);
		}

		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Cannot resolve current user',
				),
				400
			);
		}

		// Verify current password
		if ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Current password is incorrect',
				),
				400
			);
		}

		// Update WordPress password
		wp_set_password( $new_password, $user->ID );

		// Try to update MikroTik password if API is available
		$api = self::build_api();
		if ( $api ) {
			try {
				$res = $api->update_user( $user->user_login, array( 'password' => $new_password ) );
				if ( ! self::is_success( $res ) ) {
					// Log the error but don't fail the request since WP password was updated
					if ( class_exists( 'MUMB_Logger' ) ) {
						MUMB_Logger::log(
							'warning',
							'self_set_password',
							'Failed to sync password to MikroTik: ' . self::normalize_error_message( $res, 'Unknown error' ),
							array( 'user' => $user->user_login )
						);
					}
				}
			} catch ( \Throwable $e ) {
				// Log the error but don't fail the request since WP password was updated
				if ( class_exists( 'MUMB_Logger' ) ) {
					MUMB_Logger::log(
						'warning',
						'self_set_password',
						'Exception while syncing password to MikroTik: ' . $e->getMessage(),
						array( 'user' => $user->user_login )
					);
				}
			}
		}

		return array( 'success' => true );
	}



	/**
	 * @return array<int, string>
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, data: array<int, array<string, mixed>>}|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, data?: array<string, mixed>, message?: string}|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, data?: array<string, mixed>}|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, message?: string}|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, data?: array<string, mixed>, message?: string}|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, data?: array<string, mixed>}|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, data: array<int, array<string, mixed>>}|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, group?: array<string, mixed>}|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{
	 *     success: bool,
	 *     data: array<int, array<string, mixed>>,
	 *     meta: array{page: int, per_page: int, total: int, total_pages: int},
	 *     metrics: array{active: int, expiring_soon: int, expired: int},
	 *     chart: array<int, array<string, int|string>>
	 * }|WP_REST_Response
	 */


	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, token: string|null, created_at?: string, hint?: string|null, message?: string}|WP_REST_Response
	 */
	public static function cli_token_get( WP_REST_Request $req ): array|WP_REST_Response {
		$token_state = SettingsService::get_cli_token();
		if ( $token_state['hash'] === '' ) {
			$token   = self::generate_cli_token();
			$created = current_time( 'mysql' );
			self::persist_cli_token( $token, $created );
			self::log_cli_event( 'cli_token_generated', 'CLI token generated' );
			return array(
				'success'    => true,
				'token'      => $token,
				'created_at' => $created,
				'hint'       => substr( $token, 0, 4 ),
			);
		}
		$result = array(
			'success' => true,
			'token'   => null,
			'hint'    => $token_state['hint'] !== '' ? $token_state['hint'] : null,
			'message' => __( 'Token stored securely. Regenerate to retrieve a new token.', 'mikrotik-user-manager-bridge' ),
		);

		// Only include created_at if it's not empty (matches PHPDoc optional string type)
		if ( $token_state['created'] !== '' ) {
			$result['created_at'] = $token_state['created'];
		}

		return $result;
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, token: string, created_at: string, hint: string}|WP_REST_Response
	 */
	public static function cli_token_regenerate( WP_REST_Request $req ): array|WP_REST_Response {
		$token   = self::generate_cli_token();
		$created = current_time( 'mysql' );
		self::persist_cli_token( $token, $created );
		self::log_cli_event( 'cli_token_regenerated', 'CLI token regenerated' );
		return array(
			'success'    => true,
			'token'      => $token,
			'created_at' => $created,
			'hint'       => substr( $token, 0, 4 ),
		);
	}

	private static function generate_cli_token(): string {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 40, false, false );
		}
		try {
			return bin2hex( random_bytes( 20 ) );
		} catch ( \Throwable $e ) {
			return substr( md5( microtime( true ) . wp_rand() ), 0, 40 );
		}
	}

	private static function persist_cli_token( string $token, string $created ): void {
		$pepper = wp_salt( 'auth' ) . '|mumb-cli';
		$hash   = hash_hmac( 'sha256', $token, $pepper );
		SettingsService::set_cli_token( $hash, 'hmac-sha256', substr( $token, 0, 4 ), $created );
	}

	private static function log_cli_event( string $action, string $message ): void {
		if ( ! class_exists( 'MUMB_Logger' ) ) {
			return;
		}
		$meta = array( 'user' => get_current_user_id() );
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$meta['ip'] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		MUMB_Logger::log( 'notice', $action, $message, $meta );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, data: array<int, array<string, mixed>>}|WP_REST_Response
	 */
	public static function affiliates_list( WP_REST_Request $req ): array|WP_REST_Response {
		if ( self::throttle( 'affiliates_list', 30, 60 ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Rate limited',
				),
				429
			);
		}
			/** @var mixed $search_param */
			$search_param = $req->get_param( 'search' );
		$search           = is_scalar( $search_param ) ? sanitize_text_field( (string) $search_param ) : '';

		$args = array(
			'role__in' => array( 'affiliate_partner', 'administrator' ),
			'number'   => 200,
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		);
		if ( '' !== $search ) {
			$args['search']         = '*' . esc_attr( $search ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		$role_users = get_users( $args );

		$meta_users = get_users(
			array(
				'meta_key'     => 'mumb_affiliate_groups',
				'meta_compare' => '!=',
				'meta_value'   => '',
				'number'       => 200,
			)
		);

		$collection = array();
		foreach ( array_merge( $role_users, $meta_users ) as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			if ( '' !== $search ) {
				$haystacks = array( $user->user_login, $user->user_email, $user->display_name );
				$matched   = false;
				foreach ( $haystacks as $haystack ) {
					if ( stripos( $haystack, $search ) !== false ) {
						$matched = true;
						break;
					}
				}
				if ( ! $matched ) {
					continue;
				}
			}
			$collection[ $user->ID ] = $user;
		}

		$data = array();
		foreach ( $collection as $user ) {
			$groups        = class_exists( 'MUMB_Affiliates' ) ? MUMB_Affiliates::get_user_groups( $user->ID ) : array();
			$customers     = new WP_User_Query(
				array(
					'meta_key'   => 'mumb_affiliate_owner',
					'meta_value' => (string) $user->ID,
					'fields'     => 'ID',
				)
			);
			$managed_total = $customers->get_total();
			$data[]        = array(
				'id'      => $user->ID,
				'login'   => $user->user_login,
				'name'    => $user->display_name ?: $user->user_login,
				'email'   => $user->user_email,
				'groups'  => $groups,
				'users'   => $managed_total,
				'created' => $user->user_registered,
			);
		}

		usort(
			$data,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return array(
			'success' => true,
			'data'    => array_values( $data ),
		);
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool, data?: array<string, mixed>}|WP_REST_Response
	 */
	public static function affiliates_save( WP_REST_Request $req ): array|WP_REST_Response {
		if ( self::throttle( 'affiliates_save', 20, 60 ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Rate limited',
				),
				429
			);
		}
		/** @var mixed $user_id_param */
		$user_id_param = $req->get_param( 'user_id' );
		if ( null === $user_id_param ) {
			$user_id_param = $req->get_param( 'id' );
		}
		/** @var mixed $identifier */
		$identifier = $req->get_param( 'username' );
		/** @var mixed $email_param */
		$email_param = $req->get_param( 'email' );
		/** @var mixed $name_param */
		$name_param = $req->get_param( 'name' );
		/** @var mixed $raw_groups */
		$raw_groups = $req->get_param( 'groups' );

		$user = null;
		if ( is_numeric( $user_id_param ) ) {
			$user = get_user_by( 'id', (int) $user_id_param );
		}
		if ( ! $user && is_string( $identifier ) && '' !== $identifier ) {
			$user = get_user_by( 'login', sanitize_user( $identifier, true ) );
		}
		$email = is_string( $email_param ) ? sanitize_email( $email_param ) : '';
		if ( ! $user && $email ) {
			$user = get_user_by( 'email', $email );
		}

		$groups = array();
		if ( is_array( $raw_groups ) ) {
			/** @var mixed $value */
			foreach ( $raw_groups as $value ) {
				if ( is_scalar( $value ) && '' !== $value ) {
					$groups[] = sanitize_text_field( (string) $value );
				}
			}
		} elseif ( is_string( $raw_groups ) ) {
			$parts = array_map( 'trim', explode( ',', $raw_groups ) );
			foreach ( $parts as $part ) {
				if ( '' !== $part ) {
					$groups[] = sanitize_text_field( $part );
				}
			}
		}

		$generated_password = null;
		if ( ! $user ) {
			$username = is_string( $identifier ) && '' !== $identifier ? sanitize_user( $identifier, true ) : '';
			if ( '' === $username && '' !== $email ) {
				$username = sanitize_user( current( explode( '@', $email ) ), true );
			}
			if ( '' === $username || '' === $email ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Provide an existing user or both username and email to create one.',
					),
					400
				);
			}
			$password = wp_generate_password( 16, true, true );
			$user_id  = wp_insert_user(
				array(
					'user_login'   => $username,
					'user_pass'    => $password,
					'user_email'   => $email,
					'display_name' => is_string( $name_param ) && '' !== $name_param ? sanitize_text_field( $name_param ) : $username,
					'role'         => 'affiliate_partner',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => $user_id->get_error_message(),
					),
					500
				);
			}
			$user               = get_user_by( 'id', $user_id );
			$generated_password = $password;
		}

		if ( ! $user instanceof WP_User ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'User not found.',
				),
				404
			);
		}

		if ( ! in_array( 'affiliate_partner', $user->roles, true ) ) {
			$user->add_role( 'affiliate_partner' );
		}

		// Update user profile fields if provided
		$update_data   = array( 'ID' => $user->ID );
		$should_update = false;

		if ( '' !== $email && $email !== $user->user_email ) {
			$update_data['user_email'] = $email;
			$should_update             = true;
		}

		if ( is_string( $name_param ) && '' !== $name_param ) {
			$sanitized_name = sanitize_text_field( $name_param );
			if ( $sanitized_name !== $user->display_name ) {
				$update_data['display_name'] = $sanitized_name;
				$should_update               = true;
			}
		}

		if ( $should_update ) {
			$update_result = wp_update_user( $update_data );
			if ( is_wp_error( $update_result ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Failed to update user: ' . $update_result->get_error_message(),
					),
					500
				);
			}
			// Refresh user object to get updated data
			$refreshed_user = get_user_by( 'id', $user->ID );
			if ( $refreshed_user instanceof WP_User ) {
				$user = $refreshed_user;
			}
		}

		if ( class_exists( 'MUMB_Affiliates' ) ) {
			MUMB_Affiliates::store_user_groups( $user->ID, $groups );
		}

		$response = array(
			'success' => true,
			'data'    => array(
				'id'     => $user->ID,
				'login'  => $user->user_login,
				'name'   => $user->display_name ?: $user->user_login,
				'email'  => $user->user_email,
				'groups' => class_exists( 'MUMB_Affiliates' ) ? MUMB_Affiliates::get_user_groups( $user->ID ) : array(),
			),
		);
		if ( null !== $generated_password ) {
			$response['data']['generated_password'] = $generated_password;
		}

		return $response;
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $req
	 * @return array{success: bool}|WP_REST_Response
	 */
	public static function affiliates_delete( WP_REST_Request $req ): array|WP_REST_Response {
		if ( self::throttle( 'affiliates_delete', 10, 60 ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Rate limited',
				),
				429
			);
		}
		/** @var mixed $id_param */ $id_param = $req->get_param( 'id' );
		$id                                   = is_numeric( $id_param ) ? (int) $id_param : 0;
		if ( $id <= 0 ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid affiliate id',
				),
				400
			);
		}
		$user = get_user_by( 'id', $id );
		if ( ! $user instanceof WP_User ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Affiliate not found',
				),
				404
			);
		}
		if ( in_array( 'affiliate_partner', $user->roles, true ) ) {
			$user->remove_role( 'affiliate_partner' );
		}
		if ( class_exists( 'MUMB_Affiliates' ) ) {
			MUMB_Affiliates::clear_user_groups( $user->ID );
		}
		delete_user_meta( $user->ID, 'mumb_affiliate_groups' );

		// Clear ownership for managed customers
		$customers_raw = get_users(
			array(
				'meta_key'   => 'mumb_affiliate_owner',
				'meta_value' => (string) $user->ID,
				'fields'     => 'ID',
				'number'     => -1,
			)
		);
			/** @var mixed $customer_id */
		foreach ( $customers_raw as $customer_id ) {
			$customer_int = is_numeric( $customer_id ) ? (int) $customer_id : 0;
			if ( $customer_int > 0 ) {
				delete_user_meta( $customer_int, 'mumb_affiliate_owner' );
			}
		}

		return array( 'success' => true );
	}

	public static function router_capabilities( WP_REST_Request $req ): array|WP_REST_Response {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Unauthorized',
				),
				403
			);
		}
		try {
			$caps = ( new CapabilityService() )->get_capabilities();
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'error', 'capabilities', 'Capability fetch failed', array( 'error' => $e->getMessage() ) );
			}
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}

		return array(
			'success' => true,
			'data'    => $caps,
		);
	}
}
