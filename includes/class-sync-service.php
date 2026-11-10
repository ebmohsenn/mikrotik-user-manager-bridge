<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MUMB\Clients\RouterClientInterface;

class MUMB_Sync_Service {

	protected MUMB_Settings $settings;
	/** @var callable|object|string|null */
	private $loggerOverride = null;
	/** @var callable|array{string, string}|null */
	private $loggerCallable = null;

	public function __construct() {
		$this->settings = MUMB_Settings::load();
	}

	/**
	 * Allow tests to inject a logger implementation.
	 *
	 * @param callable|object|string|null $logger
	 */
	public function set_logger( $logger ): void {
		$this->loggerOverride = $logger;
		$this->loggerCallable = null;
	}

	protected function get_router_client(): ?RouterClientInterface {
		$opts = $this->build_opts();
		if ( empty( $opts ) ) {
			return null;
		}
		try {
			$api = new MikroTik_API( $opts );
			return $api->get_client();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * @return array{host:string, port:int, username:string, password:string, ssl:bool, api_timeout:int, verify_ssl:bool, retry_max?:int, retry_base_ms?:int, retry_jitter_ms?:int, um_rest?:bool, um_rest_base?:string}|array{}
	 */
	private function build_opts(): array {
		$settings = $this->settings;
		$host     = $settings->host;
		$user     = $settings->username;
		$pass     = $settings->password;
		if ( '' === $host || '' === $user || '' === $pass ) {
			return array();
		}
		$ssl  = $settings->ssl;
		$port = $settings->port;
		if ( $port <= 0 ) {
			$port = $ssl ? 8729 : 8728;
		}
		$opts               = array(
			'host'        => $host,
			'port'        => $port,
			'username'    => $user,
			'password'    => $pass,
			'ssl'         => $ssl,
			'api_timeout' => $settings->api_timeout,
		);
		$opts['verify_ssl'] = $settings->verify_ssl;
		// No custom CA path - use system defaults
		foreach ( array( 'retry_max', 'retry_base_ms', 'retry_jitter_ms' ) as $key ) {
			/** @var mixed $value */
			$value = $settings->$key;
			if ( is_int( $value ) ) {
				$opts[ $key ] = $value;
			}
		}
		if ( $settings->um_rest ) {
			$opts['um_rest'] = true;
		}
		if ( $settings->um_rest_base !== null && $settings->um_rest_base !== '' ) {
			$opts['um_rest_base'] = $settings->um_rest_base;
		}
		return $opts;
	}

	/**
	 * @return array{duration_ms:int, counts: array{users:int, groups:int, profiles:int, user_profiles:int}}
	 */
	public function sync_all(): array {
		$t0 = microtime( true );
		/** @var mixed $master_mode */
		$master_mode = get_option( 'mumb_master_mode', 0 );
		if ( is_numeric( $master_mode ) && (int) $master_mode === 1 ) {
			return $this->sync_status_only();
		}
		$this->log_event( 'info', 'sync', 'Sync started' );
		$user_count         = 0;
		$group_count        = 0;
		$profile_count      = 0;
		$user_profile_count = 0;
		try {
			$user_count = $this->sync_users();
		} catch ( \Throwable $e ) {
			$this->log_sync_error( 'users', $e->getMessage() );
		}
		try {
			$group_count = $this->sync_groups();
		} catch ( \Throwable $e ) {
			$this->log_sync_error( 'groups', $e->getMessage() );
		}
		try {
			$profile_count = $this->sync_profiles();
		} catch ( \Throwable $e ) {
			$this->log_sync_error( 'profiles', $e->getMessage() );
		}
		try {
			$user_profile_count = $this->sync_user_profiles();
		} catch ( \Throwable $e ) {
			$this->log_sync_error( 'user_profiles', $e->getMessage() );
		}
		// If mirror authority is set to 'wp', push deltas from DB to router (add/update only; non-destructive)
		if ( $this->settings->mirror_authority === 'wp' ) {
			try {
				$this->push_from_db();
			} catch ( \Throwable $e ) {
				$this->log_sync_error( 'push', $e->getMessage() );
			}
		}
		$dur    = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$counts = array(
			'users'         => $user_count,
			'groups'        => $group_count,
			'profiles'      => $profile_count,
			'user_profiles' => $user_profile_count,
		);
		update_option(
			'mumb_sync_state',
			array(
				'last_sync'        => current_time( 'mysql' ),
				'last_duration_ms' => $dur,
			),
			false
		);
		$this->log_event(
			'info',
			'sync',
			'Sync completed',
			array(
				'duration_ms' => $dur,
				'counts'      => $counts,
			)
		);
		return array(
			'duration_ms' => $dur,
			'counts'      => array(
				'users'         => $user_count,
				'groups'        => $group_count,
				'profiles'      => $profile_count,
				'user_profiles' => $user_profile_count,
			),
		);
	}

	/**
	 * Perform a lightweight status sync when WordPress is the system of record.
	 *
	 * @return array{duration_ms:int, counts: array{users:int, groups:int, profiles:int, user_profiles:int}}
	 */
	protected function sync_status_only(): array {
		$t0       = microtime( true );
		$counts   = array(
			'sessions'      => 0,
			'usage_records' => 0,
		);
		$snapshot = array(
			'captured_at' => current_time( 'mysql' ),
			'sessions'    => array(),
			'usage'       => array(),
		);

		try {
			$client = $this->get_router_client();
		} catch ( \Throwable $e ) {
			$client = null;
			$this->log_event( 'warning', 'sync_status', 'Failed to initialise API wrapper: ' . $e->getMessage() );
		}

		if ( $client ) {
			try {
				$sessions = $client->getSessions();
				if ( is_array( $sessions ) && $sessions['status'] === 'ok' ) {
					$data = $sessions['data'] ?? array();
					if ( is_array( $data ) ) {
						$snapshot['sessions'] = array_values( array_filter( $data, 'is_array' ) );
						$counts['sessions']   = count( $snapshot['sessions'] );
					}
				}
			} catch ( \Throwable $e ) {
				$this->log_event( 'warning', 'sync_status', 'Session fetch failed: ' . $e->getMessage() );
			}

			try {
				$usage = $client->getUserUsageCounters();
				if ( is_array( $usage ) && $usage['status'] === 'ok' ) {
					$data = $usage['data'] ?? array();
					if ( is_array( $data ) ) {
						$snapshot['usage']       = array_values( array_filter( $data, 'is_array' ) );
						$counts['usage_records'] = count( $snapshot['usage'] );
					}
				}
			} catch ( \Throwable $e ) {
				$this->log_event( 'warning', 'sync_status', 'Usage counter fetch failed: ' . $e->getMessage() );
			}
		}

		update_option( 'mumb_status_snapshot', $snapshot, false );

		$dur = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		update_option(
			'mumb_sync_state',
			array(
				'last_sync'        => current_time( 'mysql' ),
				'last_duration_ms' => $dur,
				'master_mode'      => 1,
			),
			false
		);
		$this->log_event(
			'info',
			'sync_status',
			'Status sync completed',
			array(
				'duration_ms' => $dur,
				'counts'      => $counts,
			)
		);

		return array(
			'duration_ms' => $dur,
			'counts'      => array(
				'users'         => 0,
				'groups'        => 0,
				'profiles'      => 0,
				'user_profiles' => 0,
			),
		);
	}

	/**
	 * @param mixed $response
	 */
	protected function log_api_warning( string $action, $response ): void {
		if ( is_array( $response ) ) {
			$context = array(
				'status' => $response['status'] ?? null,
				'error'  => $response['error_message'] ?? null,
				'keys'   => array_keys( $response ),
			);
		} else {
			$context = array( 'response_type' => gettype( $response ) );
		}
		$this->log_event( 'warning', 'sync_api', $action, $context );
	}

	/**
	 * @param array<string, mixed> $deleteScope
	 * @param array<string, mixed> $deleteList
	 */
	public function push_from_db( bool $destructive = false, array $deleteScope = array(), array $deleteList = array() ): void {
		$client = $this->get_router_client();
		if ( ! $client ) {
			return;
		}
		$this->push_groups( $client );
		$this->push_profiles( $client );
		$this->push_users( $client );
		$this->push_user_profiles( $client, $destructive, $deleteScope, $deleteList );
		if ( $destructive ) {
			$this->delete_extra_entities( $client, $deleteScope, $deleteList );
		}
	}

	protected function push_groups( RouterClientInterface $client ): void {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}
		/** @var string $p */
		$p             = $wpdb->prefix;
		$db_groups_raw = array();
		/** @var string|false $sql */
		$sql = $wpdb->prepare( 'SELECT name FROM %i', $p . 'mikrotik_groups' );
		if ( is_string( $sql ) ) {
			/** @var array<string>|null $tmp */
			$tmp = $wpdb->get_col( $sql );
			if ( is_array( $tmp ) ) {
				$db_groups_raw = $tmp;
			}
		}
		/** @var array<string> $db_groups */
		$db_groups = array_filter( array_map( 'strval', $db_groups_raw ) );

		$live = $client->getGroups();
		if ( ! $this->response_success( $live ) || ! is_array( $live['data'] ?? null ) ) {
			$this->log_api_warning( 'push_groups:get_groups', $live );
		}
		$live_names = array();
		if ( $this->response_success( $live ) ) {
			foreach ( $live['data'] ?? array() as $row ) {
				if ( is_array( $row ) && isset( $row['name'] ) && is_scalar( $row['name'] ) ) {
					$name = (string) $row['name'];
					if ( '' !== $name ) {
						$live_names[] = $name;
					}
				}
			}
		}
		$live_names = array_unique( $live_names );
		foreach ( $db_groups as $g ) {
			if ( ! in_array( $g, $live_names, true ) ) {
				try {
					$client->createGroup( array( 'name' => $g ) );
				} catch ( \Throwable $e ) {
					$this->log_api_warning( 'push_groups:add_group', array( 'message' => $e->getMessage() ) );
				}
			}
		}
	}

	protected function push_profiles( RouterClientInterface $client ): void {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}
		/** @var string $p */
		$p               = $wpdb->prefix;
		$db_profiles_raw = array();
		/** @var string|false $sql */
		$sql = $wpdb->prepare( 'SELECT name FROM %i', $p . 'mikrotik_profiles' );
		if ( is_string( $sql ) ) {
			/** @var array<string>|null $tmp */
			$tmp = $wpdb->get_col( $sql );
			if ( is_array( $tmp ) ) {
				$db_profiles_raw = $tmp;
			}
		}
		/** @var array<string> $db_profiles */
		$db_profiles = array_filter( array_map( 'strval', $db_profiles_raw ) );

		$live = $client->getProfiles();
		if ( ! $this->response_success( $live ) || ! is_array( $live['data'] ?? null ) ) {
			$this->log_api_warning( 'push_profiles:get_profiles', $live );
		}
		$live_names = array();
		if ( $this->response_success( $live ) ) {
			foreach ( $live['data'] ?? array() as $row ) {
				if ( is_array( $row ) && isset( $row['name'] ) && is_scalar( $row['name'] ) ) {
					$name = (string) $row['name'];
					if ( '' !== $name ) {
						$live_names[] = $name;
					}
				}
			}
		}
		$live_names = array_unique( $live_names );
		foreach ( $db_profiles as $name ) {
			if ( in_array( $name, $live_names, true ) ) {
				continue;
			}
			try {
				$client->createProfile( array( 'name' => $name ) );
			} catch ( \Throwable $e ) {
				$this->log_api_warning( 'push_profiles:create_profile', array( 'message' => $e->getMessage() ) );
			}
		}
	}

	protected function push_users( RouterClientInterface $client ): void {
		global $wpdb;
		/** @var string $p */
		$p = $wpdb->prefix;
		/** @var array<int, mixed>|null $db_users */
		$db_users = $wpdb->get_results( $wpdb->prepare( 'SELECT username, profile, comment FROM %i', $p . 'mikrotik_users' ), ARRAY_A ) ?: array();
		$live     = $client->getUsers();
		if ( ! $this->response_success( $live ) || ! is_array( $live['data'] ?? null ) ) {
			$this->log_api_warning( 'push_users:get_users', $live );
		}
		$live_map = array();
		if ( $this->response_success( $live ) ) {
			$data = $live['data'] ?? null;
			if ( is_array( $data ) ) {
				foreach ( $data as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					/** @var mixed $uname_val */
					$uname_val = $row['username'] ?? ( $row['login'] ?? ( $row['name'] ?? null ) );
					if ( is_scalar( $uname_val ) ) {
						$uname = (string) $uname_val;
						if ( '' !== $uname ) {
							$live_map[ strtolower( $uname ) ] = $row;
						}
					}
				}
			}
		}
		if ( is_array( $db_users ) ) {
			foreach ( $db_users as $u ) {
				if ( ! is_array( $u ) ) {
					continue;
				}
				if ( ! array_key_exists( 'username', $u ) ) {
					continue;
				}
				$username_raw = $u['username'];
				if ( ! is_scalar( $username_raw ) ) {
					continue;
				}
				$username = (string) $username_raw;
				if ( '' === $username ) {
					continue;
				}
				$key = strtolower( $username );
				if ( ! array_key_exists( $key, $live_map ) ) {
					$payload = array( 'username' => $username );
					if ( array_key_exists( 'comment', $u ) ) {
						/** @var mixed $comment_raw */
						$comment_raw = $u['comment'];
						if ( is_scalar( $comment_raw ) ) {
							$comment = (string) $comment_raw;
							if ( '' !== $comment ) {
								$payload['comment'] = $comment;
							}
						}
					}
					if ( array_key_exists( 'profile', $u ) ) {
						/** @var mixed $profile_raw */
						$profile_raw = $u['profile'];
						if ( is_scalar( $profile_raw ) ) {
							$profile = (string) $profile_raw;
							if ( '' !== $profile ) {
								$payload['group'] = $profile;
							}
						}
					}
					try {
						$client->createUser( $payload );
					} catch ( \Throwable $e ) {
						$this->log_api_warning( 'push_users:add_user', array( 'message' => $e->getMessage() ) );
					}
					continue;
				}
				$row = $live_map[ $key ];
				if ( ! is_array( $row ) ) {
					continue;
				}
				$payload    = array();
				$db_comment = '';
				if ( array_key_exists( 'comment', $u ) ) {
					/** @var mixed $comment_raw */
					$comment_raw = $u['comment'];
					if ( is_scalar( $comment_raw ) ) {
						$db_comment = (string) $comment_raw;
					}
				}
				$live_comment = '';
				if ( array_key_exists( 'comment', $row ) ) {
					/** @var mixed $live_comment_raw */
					$live_comment_raw = $row['comment'];
					if ( is_scalar( $live_comment_raw ) ) {
						$live_comment = (string) $live_comment_raw;
					}
				}
				if ( '' !== $db_comment && $db_comment !== $live_comment ) {
					$payload['comment'] = $db_comment;
				}
				$db_group = '';
				if ( array_key_exists( 'profile', $u ) ) {
					/** @var mixed $profile_raw */
					$profile_raw = $u['profile'];
					if ( is_scalar( $profile_raw ) ) {
						$db_group = (string) $profile_raw;
					}
				}
				$live_group = '';
				if ( array_key_exists( 'group', $row ) ) {
					/** @var mixed $live_group_raw */
					$live_group_raw = $row['group'];
					if ( is_scalar( $live_group_raw ) ) {
						$live_group = (string) $live_group_raw;
					}
				}
				if ( '' !== $db_group && $db_group !== $live_group ) {
					$payload['group'] = $db_group;
				}
				if ( ! empty( $payload ) ) {
					try {
						$client->updateUser( $username, $payload );
					} catch ( \Throwable $e ) {
						$this->log_api_warning( 'push_users:update_user', array( 'message' => $e->getMessage() ) );
					}
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $deleteScope
	 * @param array<string, mixed> $deleteList
	 */
	protected function push_user_profiles( RouterClientInterface $client, bool $destructive = false, array $deleteScope = array(), array $deleteList = array() ): void {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}
		/** @var string $p */
		$p = $wpdb->prefix;
		/** @var array<int, array<string, mixed>> $db */
		$db = array();
		/** @var string|false $sql */
		$sql = $wpdb->prepare( 'SELECT username, profile FROM %i', $p . 'mikrotik_user_profiles' );
		if ( is_string( $sql ) ) {
			$tmp = $wpdb->get_results( $sql, ARRAY_A );
			if ( is_array( $tmp ) ) {
				$db = $tmp;
			}
		}
		$live = $client->getUserProfiles();
		if ( ! $this->response_success( $live ) || ! is_array( $live['data'] ?? null ) ) {
			$this->log_api_warning( 'push_user_profiles:get_user_profiles', $live );
		}
		$live_pairs = array();
		if ( $this->response_success( $live ) ) {
			foreach ( $live['data'] ?? array() as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				/** @var mixed $user_val */
				$user_val = $row['user'] ?? ( $row['users'] ?? ( $row['name'] ?? '' ) );
				/** @var mixed $prof_val */
				$prof_val = $row['profile'] ?? '';
				$u        = strtolower( is_scalar( $user_val ) ? (string) $user_val : '' );
				$pr       = is_scalar( $prof_val ) ? (string) $prof_val : '';
				if ( '' !== $u && '' !== $pr ) {
					$live_pairs[ $u . '|' . $pr ] = true;
				}
			}
		}
		/** @var array<string, mixed> $r */
		foreach ( $db as $r ) {
			/** @var mixed $u_val */
			$u_val = $r['username'] ?? '';
			/** @var mixed $pr_val */
			$pr_val = $r['profile'] ?? '';
			$u      = is_scalar( $u_val ) ? strtolower( (string) $u_val ) : '';
			$pr     = is_scalar( $pr_val ) ? (string) $pr_val : '';
			if ( '' === $u || '' === $pr ) {
				continue;
			}
				$key = $u . '|' . $pr;
			if ( isset( $live_pairs[ $key ] ) ) {
				continue;
			}
				$user_for_assign = is_scalar( $r['username'] ?? null ) ? (string) $r['username'] : '';
			if ( '' === $user_for_assign ) {
				continue;
			}
			try {
				$client->assignProfile( $user_for_assign, $pr );
			} catch ( \Throwable $e ) {
				$this->log_api_warning( 'push_user_profiles:assign_profile', array( 'message' => $e->getMessage() ) );
			}
		}

		if ( $destructive ) {
			// Remove assignments present on router but not in DB
			$db_pairs = array();
			/** @var array<string, mixed> $r */
			foreach ( $db as $r ) {
				/** @var mixed $username_val */
				$username_val = $r['username'] ?? '';
				/** @var mixed $profile_val */
				$profile_val  = $r['profile'] ?? '';
				$uname        = is_scalar( $username_val ) ? strtolower( (string) $username_val ) : '';
				$prof         = is_scalar( $profile_val ) ? (string) $profile_val : '';
				if ( '' !== $uname && '' !== $prof ) {
					$db_pairs[ $uname . '|' . $prof ] = true;
				}
			}
			foreach ( $live_pairs as $key => $_ ) {
				if ( ! empty( $deleteList['user_profiles'] ) ) {
					$allowed = false;
					/** @var mixed $row */
					foreach ( (array) $deleteList['user_profiles'] as $row ) {
						/** @var mixed $row */
						if ( ! is_array( $row ) ) {
							continue;
						}
						$k = strtolower( (string) ( $row['username'] ?? '' ) ) . '|' . (string) ( $row['profile'] ?? '' );
						if ( $k === $key ) {
							$allowed = true;
							break;
						}
					}
					if ( ! $allowed ) {
						continue;
					}
				} elseif ( ! empty( $deleteScope ) && empty( $deleteScope['user_profiles'] ) ) {
					continue; // scoped deletes exclude user_profiles
				} elseif ( ! empty( $db_pairs[ $key ] ) ) {
					continue; // default: delete only extras
				}
				$parts = explode( '|', $key, 2 );
				$uname = $parts[0] ?? '';
				$prof  = $parts[1] ?? '';
				if ( $uname && $prof ) {
					try {
						$client->removeProfile( $uname, $prof );
					} catch ( \Throwable $e ) {
						$this->log_api_warning( 'push_user_profiles:remove', array( 'message' => $e->getMessage() ) );
					}
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $deleteScope
	 * @param array<string, mixed> $deleteList
	 */
	protected function delete_extra_entities( RouterClientInterface $client, array $deleteScope, array $deleteList ): void {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}
		/** @var string $p */
		$p = $wpdb->prefix;
		// Groups
		if ( empty( $deleteScope ) || ! empty( $deleteScope['groups'] ) ) {
			/** @var array<int, string>|false|null $tmp */
			$tmp = null;
			/** @var string|false $sql */
			$sql = $wpdb->prepare( 'SELECT name FROM %i', $p . 'mikrotik_groups' );
			if ( is_string( $sql ) ) {
				$tmp = $wpdb->get_col( $sql );
			}
			if ( ! is_array( $tmp ) ) {
				$tmp = array();
			}
			$db   = array_filter( array_map( 'strval', $tmp ) );
			$live = $client->getGroups();
			if ( ! $this->response_success( $live ) || ! is_array( $live['data'] ?? null ) ) {
				$this->log_api_warning( 'delete_extra_entities:get_groups', $live );
			}
			$live_names = array();
			if ( $this->response_success( $live ) ) {
				$data = $live['data'] ?? null;
				if ( is_array( $data ) ) {
					foreach ( $data as $row ) {
						if ( ! is_array( $row ) ) {
							continue;
						}
						if ( array_key_exists( 'name', $row ) ) {
							/** @var mixed $name_raw */
							$name_raw = $row['name'];
							if ( is_scalar( $name_raw ) ) {
								$name = (string) $name_raw;
								if ( '' !== $name ) {
									$live_names[ $name ] = true;
								}
							}
						}
					}
				}
			}
			foreach ( array_keys( $live_names ) as $name ) {
				if ( ! empty( $deleteList['groups'] ) ) {
					$allowed = (array) $deleteList['groups'];
					if ( ! in_array( $name, $allowed, true ) ) {
						continue;
					}
				}
				if ( ! in_array( $name, $db, true ) ) {
					try {
						$client->deleteGroup( $name );
					} catch ( \Throwable $e ) {
						$this->log_api_warning( 'delete_extra_entities:delete_group', array( 'message' => $e->getMessage() ) );
					}
				}
			}
		}
		// Profiles
		if ( empty( $deleteScope ) || ! empty( $deleteScope['profiles'] ) ) {
			/** @var array<int, string>|false|null $tmp */
			$tmp = null;
			/** @var string|false $sql */
			$sql = $wpdb->prepare( 'SELECT name FROM %i', $p . 'mikrotik_profiles' );
			if ( is_string( $sql ) ) {
				$tmp = $wpdb->get_col( $sql );
			}
			if ( ! is_array( $tmp ) ) {
				$tmp = array();
			}
			$db   = array_filter( array_map( 'strval', $tmp ) );
			$live = $client->getProfiles();
			if ( ! $this->response_success( $live ) || ! is_array( $live['data'] ?? null ) ) {
				$this->log_api_warning( 'delete_extra_entities:get_profiles', $live );
			}
			$live_names = array();
			if ( $this->response_success( $live ) ) {
				$data = $live['data'] ?? null;
				if ( is_array( $data ) ) {
					foreach ( $data as $row ) {
						if ( ! is_array( $row ) ) {
							continue;
						}
						if ( array_key_exists( 'name', $row ) ) {
							/** @var mixed $name_raw */
							$name_raw = $row['name'];
							if ( is_scalar( $name_raw ) ) {
								$name = (string) $name_raw;
								if ( '' !== $name ) {
									$live_names[ $name ] = true;
								}
							}
						}
					}
				}
			}
			foreach ( array_keys( $live_names ) as $name ) {
				if ( ! empty( $deleteList['profiles'] ) ) {
					$allowed = (array) $deleteList['profiles'];
					if ( ! in_array( $name, $allowed, true ) ) {
						continue;
					}
				}
				if ( ! in_array( $name, $db, true ) ) {
					try {
						$client->deleteProfile( $name );
					} catch ( \Throwable $e ) {
						$this->log_api_warning( 'delete_extra_entities:delete_profile', array( 'message' => $e->getMessage() ) );
					}
				}
			}
		}
		// Users (cautious: delete only if no user_profiles in DB or explicit list provided)
		if ( empty( $deleteScope ) || ! empty( $deleteScope['users'] ) ) {
			/** @var array<int, string>|false|null $db_users_raw */
			$db_users_raw = null;
			/** @var string|false $sql_users */
			$sql_users = $wpdb->prepare( 'SELECT username FROM %i', $p . 'mikrotik_users' );
			if ( is_string( $sql_users ) ) {
				$db_users_raw = $wpdb->get_col( $sql_users );
			}
			if ( ! is_array( $db_users_raw ) ) {
				$db_users_raw = array();
			}
			$db_users = array_map( 'strval', $db_users_raw );
			/** @var array<int, string>|false|null $db_up_raw */
			$db_up_raw = null;
			/** @var string|false $sql_up */
			$sql_up = $wpdb->prepare( 'SELECT DISTINCT username FROM %i', $p . 'mikrotik_user_profiles' );
			if ( is_string( $sql_up ) ) {
				$db_up_raw = $wpdb->get_col( $sql_up );
			}
			if ( ! is_array( $db_up_raw ) ) {
				$db_up_raw = array();
			}
			$db_up = array_map( 'strval', $db_up_raw );
			/** @var mixed $live */
			$live           = $client->getUsers();
			$live_usernames = array();
			if ( is_array( $live ) ) {
				/** @var array<string, mixed> $live */
				if ( $this->response_success( $live ) ) {
					/** @var array<string,mixed> $liveArr */
					$liveArr = $live;
					/** @var array<int,array<string,mixed>>|null $data */
					$data = isset( $liveArr['data'] ) && is_array( $liveArr['data'] ) ? $liveArr['data'] : null;
					if ( is_array( $data ) ) {
						foreach ( $data as $row ) {
							if ( ! is_array( $row ) ) {
								continue;
							}
							/** @var array<string,mixed> $row */
							/** @var mixed $uname_val */
							$uname_val = $row['username'] ?? ( $row['login'] ?? ( $row['name'] ?? null ) );
							if ( is_scalar( $uname_val ) ) {
								$uname = (string) $uname_val;
								if ( '' !== $uname ) {
									$live_usernames[] = $uname;
								}
							}
						}
					}
				} else {
					$this->log_api_warning( 'delete_extra_entities:get_users', $live );
				}
			} else {
				$this->log_api_warning( 'delete_extra_entities:get_users', $live );
			}
			foreach ( $live_usernames as $uname ) {
				if ( ! empty( $deleteList['users'] ) ) {
					$allowed = (array) $deleteList['users'];
					if ( ! in_array( $uname, $allowed, true ) ) {
						continue;
					}
				}
				if ( in_array( $uname, $db_users, true ) ) {
					continue;
				}
				if ( in_array( $uname, $db_up, true ) ) {
					continue;
				}
				try {
					$client->deleteUser( $uname );
				} catch ( \Throwable $e ) {
					$this->log_api_warning( 'delete_extra_entities:delete_user', array( 'message' => $e->getMessage() ) );
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function response_success( array $response ): bool {
		return isset( $response['status'] ) && $response['status'] === 'ok';
	}

	public function sync_users(): int {
		// Prefer REST UM if enabled
		if ( $this->settings->um_rest && ! empty( $this->settings->um_rest_base ) ) {
			$password = $this->settings->decrypted_password();
			$client   = new MUMB_UM_REST( $this->settings->um_rest_base, $this->settings->username, $password, $this->settings->ssl, $this->settings->api_timeout );
			$r        = $client->list_users();
			if ( is_wp_error( $r ) ) {
				$this->log_sync_error( 'users', $r->get_error_message() );
				return 0;
			}
			$rows = $r;
		} else {
			// Use router client instead of undefined $api property
			$client = $this->get_router_client();
			if ( ! $client ) {
				return 0;
			}
			$api  = new MikroTik_API( $this->build_opts() );
			$rows = $api->get_users();
			if ( ! is_array( $rows ) || ! isset( $rows['data'] ) ) {
				$rows = array();
			} else {
				$rows = $rows['data'];
			}
		}
		if ( ! is_array( $rows ) ) {
			return 0;
		}
		global $wpdb;
		/** @var string $p */
		$p    = $wpdb->prefix;
		$now  = current_time( 'mysql' );
		$n    = 0;
		$seen = array();
		foreach ( $rows as $r ) {
			/** @var array<string,mixed> $r */
			/** @var mixed $mid_raw */
			$mid_raw = $r['.id'] ?? '';
			/** @var mixed $rest_id_raw */
			$rest_id_raw = $r['id'] ?? '';
			/** @var mixed $username_raw */
			$username_raw = $r['username'] ?? $r['login'] ?? $r['name'] ?? '';
			/** @var mixed $comment_raw */
			$comment_raw = $r['comment'] ?? '';
			// Best-effort profile/group mapping
			/** @var mixed $profile_raw */
			$profile_raw = $r['group'] ?? ( $r['profile'] ?? ( $r['actual-profile'] ?? '' ) );

			$mid      = is_string( $mid_raw ) || is_numeric( $mid_raw ) ? (string) $mid_raw : '';
			$rest_id  = is_string( $rest_id_raw ) || is_numeric( $rest_id_raw ) ? (string) $rest_id_raw : '';
			$username = is_string( $username_raw ) || is_numeric( $username_raw ) ? (string) $username_raw : '';
			$comment  = is_string( $comment_raw ) || is_numeric( $comment_raw ) ? (string) $comment_raw : '';
			$profile  = is_string( $profile_raw ) || is_numeric( $profile_raw ) ? (string) $profile_raw : '';
			if ( ! $mid || ! $username ) {
				continue;
			}
			$seen[] = $username;
			$id     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE username = %s LIMIT 1', $p . 'mikrotik_users', $username ) );
			if ( $id ) {
				$wpdb->update(
					$p . 'mikrotik_users',
					array(
						'um_id'      => $mid,
						'rest_id'    => '' !== $rest_id ? $rest_id : null,
						'comment'    => $comment,
						'profile'    => $profile,
						'updated_at' => $now,
					),
					array( 'id' => $id ),
					array( '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->insert(
					$p . 'mikrotik_users',
					array(
						'um_id'      => $mid,
						'rest_id'    => '' !== $rest_id ? $rest_id : null,
						'username'   => $username,
						'comment'    => $comment,
						'profile'    => $profile,
						'updated_at' => $now,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}
			++$n;

			// Mirror each MikroTik user to a WordPress user for WooCommerce compatibility
			if ( class_exists( 'MUMB_WP_Users' ) ) {
				/** @var mixed $group_raw */
				$group_raw = $r['group'] ?? '';
				$group_val = is_string( $group_raw ) || is_numeric( $group_raw ) ? (string) $group_raw : '';

				$meta = array(
					'group'   => $group_val,
					'comment' => $comment,
					'profile' => $profile,
				);
				if ( isset( $r['shared-users'] ) ) {
					/** @var mixed $shared_users_raw */
					$shared_users_raw     = $r['shared-users'];
					$shared_users_val     = is_numeric( $shared_users_raw ) ? (int) $shared_users_raw : 0;
					$meta['shared-users'] = $shared_users_val;
				}
				if ( isset( $r['disabled'] ) ) {
					/** @var mixed $disabled_raw */
					$disabled_raw     = $r['disabled'];
					$disabled_val     = is_string( $disabled_raw ) || is_numeric( $disabled_raw ) ? (string) $disabled_raw : '';
					$meta['disabled'] = ( $disabled_val === 'true' || (int) $disabled_val === 1 );
				}
				MUMB_WP_Users::ensure_user( $username, $meta );
			}
		}
		// Best-effort dedup by username (keep lowest id)
		try {
			$wpdb->query( 'DELETE u1 FROM ' . $p . 'mikrotik_users u1 INNER JOIN ' . $p . 'mikrotik_users u2 ON u1.username = u2.username AND u1.id > u2.id' );
		} catch ( \Throwable $e ) {
			/* ignore */
		}
		// Remove stale users not present upstream
		try {
			$seen = array_unique(
				array_filter(
					$seen,
					static function ( string $value ): bool {
						/** @psalm-suppress DocblockTypeContradiction */
						return '' !== $value;
					}
				)
			);
			if ( ! empty( $seen ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $seen ), '%s' ) );
				$table_name   = $p . 'mikrotik_users';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table_name}` WHERE username NOT IN ($placeholders)", ...$seen ) );
			} else {
				// Nothing returned from upstream; avoid mass delete.
			}
		} catch ( \Throwable $e ) {
			/* ignore */
		}
		return $n;
	}

	public function sync_user_profiles(): int {
		$rows = array();
		// REST UM may or may not expose user-profile via REST; prefer API here.
		$client = $this->get_router_client();
		if ( $client ) {
			$api    = new MikroTik_API( $this->build_opts() );
			$result = $api->get_user_profiles();
			if ( is_array( $result ) && isset( $result['data'] ) ) {
				$rows = $result['data'];
			}
		}
		if ( ! is_array( $rows ) ) {
			return 0;
		}
		global $wpdb;
		/** @var string $p */
		$p    = $wpdb->prefix;
		$now  = current_time( 'mysql' );
		$n    = 0;
		$seen = array();
		foreach ( $rows as $r ) {
			/** @var mixed $user_field */
			$user_field = $r['user'] ?? $r['users'] ?? $r['name'] ?? '';
			$user       = is_scalar( $user_field ) ? (string) $user_field : '';
			/** @var mixed $profile_field */
			$profile_field = $r['profile'] ?? '';
			$profile       = is_scalar( $profile_field ) ? (string) $profile_field : '';
			$expires_at    = null;
			if ( ! empty( $r['till-time'] ) ) {
				$till_time = is_scalar( $r['till-time'] ) ? (string) $r['till-time'] : '';
				$ts        = strtotime( $till_time );
				if ( $ts !== false ) {
					$expires_at = gmdate( 'Y-m-d H:i:s', $ts );
				}
			}
			if ( ! $user || ! $profile ) {
				$this->log_event(
					'warning',
					'sync_user_profile_skip',
					'Skipping malformed user-profile row',
					array(
						'raw_user'    => $r['user'] ?? ( $r['users'] ?? ( $r['name'] ?? null ) ),
						'raw_profile' => $r['profile'] ?? null,
						'keys'        => array_keys( $r ),
					)
				);
				continue;
			}
			$seen[] = $user . '|' . $profile;
			// Upsert by username+profile without relying on a unique index
			try {
				$wpdb->delete(
					$p . 'mikrotik_user_profiles',
					array(
						'username' => $user,
						'profile'  => $profile,
					),
					array( '%s', '%s' )
				);
			} catch ( \Throwable $e ) {
				/* ignore */
			}
			$wpdb->insert(
				$p . 'mikrotik_user_profiles',
				array(
					'username'   => $user,
					'profile'    => $profile,
					'um_id'      => isset( $r['.id'] ) && is_scalar( $r['.id'] ) ? (string) $r['.id'] : null,
					'rest_id'    => isset( $r['id'] ) && is_scalar( $r['id'] ) ? (string) $r['id'] : null,
					'expires_at' => $expires_at,
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			++$n;
		}
		// Optionally remove stale entries when we have some upstream data
		/** @var wpdb $wpdb */
		global $wpdb;
		try {
			$seen = array_unique( $seen );
			if ( ! empty( $seen ) ) {
				// Build WHERE NOT (username='u' AND profile='p') for all seen; simpler approach is truncate refill but we avoid destructive ops.
				// As a compromise, remove entries for usernames not present in any seen pair.
				$usernames = array();
				foreach ( $seen as $pair ) {
					$parts = explode( '|', $pair, 2 );
					if ( ! empty( $parts[0] ) ) {
						$usernames[] = $parts[0];
					}
				}
				$usernames = array_unique(
					array_filter(
						$usernames,
						static function ( string $value ): bool {
							/** @psalm-suppress DocblockTypeContradiction */
							return '' !== $value;
						}
					)
				);
				if ( ! empty( $usernames ) ) {
					$ph    = implode( ',', array_fill( 0, count( $usernames ), '%s' ) );
					$table = $p . 'mikrotik_user_profiles';
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					/** @var string|false $sql */
					// @phpstan-ignore-next-line
					$sql = $wpdb->prepare( "DELETE FROM `{$table}` WHERE username NOT IN ({$ph})", ...$usernames );
					if ( is_string( $sql ) ) {
						$wpdb->query( $sql );
					}
				}
			}
		} catch ( \Throwable $e ) {
			/* ignore */
		}
		return $n;
	}

	public function sync_groups(): int {
		$rows = array();
		if ( $this->settings->um_rest && ! empty( $this->settings->um_rest_base ) ) {
			$password = $this->settings->decrypted_password();
			$client   = new MUMB_UM_REST( $this->settings->um_rest_base, $this->settings->username, $password, $this->settings->ssl, $this->settings->api_timeout );
			$r        = $client->list_groups();
			if ( is_wp_error( $r ) ) {
				// Fallback to API if available
				$client = $this->get_router_client();
				if ( $client ) {
					$api    = new MikroTik_API( $this->build_opts() );
					$result = $api->get_groups();
					if ( is_array( $result ) && isset( $result['data'] ) ) {
						$rows = $result['data'];
					}
				} else {
					$this->log_sync_error( 'groups', $r->get_error_message() );
					return 0;
				}
			} else {
				$rows = $r;
			}
		} else {
			$client = $this->get_router_client();
			if ( ! $client ) {
				return 0;
			}
			$api    = new MikroTik_API( $this->build_opts() );
			$result = $api->get_groups();
			if ( is_array( $result ) && isset( $result['data'] ) ) {
				$rows = $result['data'];
			} else {
				$rows = array();
			}
		}
		if ( ! is_array( $rows ) ) {
			return 0;
		}
		global $wpdb;
		/** @var string $p */
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql' );
		$n   = 0;
		foreach ( $rows as $r ) {
			$name = (string) ( $r['name'] ?? '' );
			if ( ! $name ) {
				continue;
			}
			$wpdb->replace(
				$p . 'mikrotik_groups',
				array(
					'name'       => $name,
					'um_id'      => isset( $r['.id'] ) ? (string) $r['.id'] : null,
					'rest_id'    => isset( $r['id'] ) ? (string) $r['id'] : null,
					'attributes' => $r['attributes'] ?? null,
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
			++$n;
		}
		return $n;
	}

	public function sync_profiles(): int {
		if ( $this->settings->um_rest && ! empty( $this->settings->um_rest_base ) ) {
			$password = $this->settings->decrypted_password();
			$client   = new MUMB_UM_REST( $this->settings->um_rest_base, $this->settings->username, $password, $this->settings->ssl, $this->settings->api_timeout );
			$r        = $client->list_profiles();
			if ( is_wp_error( $r ) ) {
				$this->log_sync_error( 'profiles', $r->get_error_message() );
				return 0;
			}
			$rows = $r;
		} else {
			$client = $this->get_router_client();
			if ( ! $client ) {
				return 0;
			}
			$api    = new MikroTik_API( $this->build_opts() );
			$result = $api->get_profiles();
			if ( is_array( $result ) && isset( $result['data'] ) ) {
				$rows = $result['data'];
			} else {
				$rows = array();
			}
		}
		if ( ! is_array( $rows ) ) {
			return 0;
		}
		global $wpdb;
		/** @var string $p */
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql' );
		$n   = 0;
		foreach ( $rows as $r ) {
			$name = (string) ( $r['name'] ?? '' );
			if ( ! $name ) {
				continue;
			}
			$wpdb->replace(
				$p . 'mikrotik_profiles',
				array(
					'name'       => $name,
					'um_id'      => isset( $r['.id'] ) ? (string) $r['.id'] : null,
					'rest_id'    => isset( $r['id'] ) ? (string) $r['id'] : null,
					'validity'   => isset( $r['validity'] ) ? (string) $r['validity'] : ( isset( $r['valid-for'] ) ? (string) $r['valid-for'] : null ),
					'raw'        => wp_json_encode( $r ),
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			++$n;
		}
		return $n;
	}

	/**
	 * @param string               $level
	 * @param string               $action
	 * @param string               $message
	 * @param array<string, mixed> $meta
	 */
	protected function log_event( string $level, string $action, string $message = '', array $meta = array() ): void {
		$logger = $this->resolve_logger();
		if ( ! is_callable( $logger ) ) {
			return;
		}
		try {
			$logger( $level, $action, $message, $meta );
		} catch ( \Throwable $ignored ) {
			// Silently ignore logging failures during tests.
		}
	}

	protected function log_sync_error( string $area, string $msg ): void {
		$this->log_event( 'error', 'sync', $area . ': ' . $msg );
		update_option(
			'mumb_sync_notice',
			array(
				'type'    => 'error',
				'message' => 'Sync failed: ' . $area . ' — ' . $msg,
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * @return callable|array{string, string}|null
	 */
	private function resolve_logger() {
		if ( $this->loggerCallable !== null ) {
			return $this->loggerCallable;
		}
		if ( $this->loggerOverride !== null ) {
			$override = $this->loggerOverride;
			if ( is_callable( $override ) ) {
				$this->loggerCallable = $override;
				return $this->loggerCallable;
			}
			if ( is_object( $override ) && method_exists( $override, 'log' ) ) {
				$this->loggerCallable = array( $override, 'log' );
				return $this->loggerCallable;
			}
			if ( is_string( $override ) && class_exists( $override ) && method_exists( $override, 'log' ) ) {
				$this->loggerCallable = array( $override, 'log' );
				return $this->loggerCallable;
			}
		}
		if ( class_exists( 'MUMB_Logger' ) && method_exists( MUMB_Logger::class, 'log' ) ) {
			$this->loggerCallable = array( MUMB_Logger::class, 'log' );
			return $this->loggerCallable;
		}
		$this->loggerCallable = null;
		return null;
	}
}
