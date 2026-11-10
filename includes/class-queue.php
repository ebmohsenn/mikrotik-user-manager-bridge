<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MUMB\Clients\RouterClientInterface;
use MUMB\Services\SettingsService;

class MUMB_Queue {

	const OPTION_KEY         = 'mumb_job_queue';
	const MAX_ATTEMPTS       = 5;
	const HISTORY_OPTION_KEY = 'mumb_job_history';
	const HISTORY_LIMIT      = 50;

	private static function max_attempts(): int {
		/** @var mixed $filtered */
		$filtered = apply_filters( 'mumb_queue_max_attempts', self::MAX_ATTEMPTS );
		$value    = is_numeric( $filtered ) ? (int) $filtered : self::MAX_ATTEMPTS;
		return $value > 0 ? $value : self::MAX_ATTEMPTS;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function notify_failure( string $key, array $payload, string $error, int $attempts ): void {
		do_action( 'mumb_queue_job_failed', $key, $payload, $error, $attempts );
		/** @var mixed $recipient */
		$recipient = apply_filters( 'mumb_queue_failure_email', get_option( 'admin_email' ) );
		$email     = is_string( $recipient ) ? trim( $recipient ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}
		$subject    = sprintf( '[%s] Queue job failed (%s)', MUMB_Branding::name(), $key );
		$body_lines = array(
			sprintf( 'Job key: %s', $key ),
			sprintf( 'Attempts: %d', $attempts ),
			sprintf( 'Error: %s', $error ?: 'Unknown error' ),
		);
		if ( ! empty( $payload['type'] ) ) {
			$body_lines[] = 'Type: ' . $payload['type'];
		}
		if ( ! empty( $payload['user'] ) ) {
			$body_lines[] = 'User: ' . $payload['user'];
		}
		$body = implode( "\n", $body_lines );
		wp_mail( $email, $subject, $body );
	}

	/**
	 * @param array{type:string, user?:string, profile?:string, data?:array<string, mixed>} $payload
	 */
	public static function enqueue( string $key, array $payload ): string {
		$q   = SettingsService::get_job_queue();
		$now = time();
		if ( isset( $q[ $key ] ) && is_array( $q[ $key ] ) ) {
			$existing  = $q[ $key ];
			$attempts  = isset( $existing['attempts'] ) ? (int) $existing['attempts'] : 0;
			$q[ $key ] = array_merge(
				$existing,
				array(
					'payload'    => $payload,
					'attempts'   => $attempts,
					'next_run'   => $now,
					'status'     => 'pending',
					'last_error' => '',
					'updated_at' => $now,
				)
			);
			if ( empty( $q[ $key ]['created_at'] ) ) {
				$q[ $key ]['created_at'] = $now;
			}
		} else {
			$q[ $key ] = array(
				'payload'    => $payload,
				'attempts'   => 0,
				'next_run'   => $now,
				'status'     => 'pending',
				'last_error' => '',
				'created_at' => $now,
				'updated_at' => $now,
			);
		}
		SettingsService::set_job_queue( $q );
		return $key;
	}

	/**
	 * @return array{processed:int, remaining:int}
	 */
	public static function process(): array {
		$q = SettingsService::get_job_queue();

		// Debug: Log queue contents
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// Debug: error_log( '[MUMB_Queue] Starting process() with ' . count( $q ) . ' jobs in queue' );
		}

		if ( empty( $q ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Debug: error_log( '[MUMB_Queue] Queue is empty, returning early' );
			}
			return array(
				'processed' => 0,
				'remaining' => 0,
			);
		}
		$settings = MUMB_Settings::load();
		if ( ! $settings->is_configured() ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Debug: error_log( '[MUMB_Queue] Settings not configured, returning early' );
			}
			return array(
				'processed' => 0,
				'remaining' => count( $q ),
			);
		}
		$opts = self::build_opts( $settings );
		if ( empty( $opts ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Debug: error_log( '[MUMB_Queue] build_opts returned empty, returning early' );
			}
			return array(
				'processed' => 0,
				'remaining' => count( $q ),
			);
		}
		try {
			$client = ( new MikroTik_API( $opts ) )->get_client();
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Debug: error_log( '[MUMB_Queue] MikroTik_API client created successfully' );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Debug: error_log( '[MUMB_Queue] Failed to create MikroTik_API client: ' . $e->getMessage() );
			}
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'error', 'queue', 'Failed to initialise router client', array( 'message' => $e->getMessage() ) );
			}
			return array(
				'processed' => 0,
				'remaining' => count( $q ),
			);
		}
		$done = 0;
		foreach ( $q as $key => $job ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Debug: error_log( '[MUMB_Queue] Processing job: ' . $key );
			}

			if ( ! is_array( $job ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// Debug: error_log( '[MUMB_Queue] Job ' . $key . ' is not an array, skipping' );
				}
				continue;
			}
			$next_run = isset( $job['next_run'] ) && is_numeric( $job['next_run'] ) ? (int) $job['next_run'] : 0;
			if ( time() < $next_run ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// Debug: error_log( '[MUMB_Queue] Job ' . $key . ' scheduled for future (next_run: ' . $next_run . ', current: ' . time() . '), skipping' );
				}
				continue;
			}
			$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
			$type    = isset( $payload['type'] ) ? (string) $payload['type'] : '';

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$payload_json = wp_json_encode( $payload );
				if ( $payload_json !== false ) {
					// Debug: error_log( '[MUMB_Queue] Job ' . $key . ' type: ' . $type . ', payload: ' . $payload_json );
				}
			}

			try {
				$result = array(
					'ok'    => true,
					'error' => '',
				);
				switch ( $type ) {
					case 'assign_profile':
						$result = self::job_assign_profile( $client, $payload );
						break;
					case 'create_user':
						$result = self::job_create_user( $client, $payload );
						break;
					case 'update_user':
						$result = self::job_update_user( $client, $payload );
						break;
					case 'disable_user':
						$result = self::job_disable_user( $client, $payload );
						break;
					default:
						$result = array(
							'ok'    => true,
							'error' => '',
						);
						break;
				}
				$ok  = $result['ok'];
				$err = $result['error'];

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// Debug: error_log( '[MUMB_Queue] Job ' . $key . ' result - OK: ' . ( $ok ? 'true' : 'false' ) . ', Error: ' . $err );
				}
			} catch ( \Throwable $e ) {
				$ok  = false;
				$err = $e->getMessage();
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// Debug: error_log( '[MUMB_Queue] Job ' . $key . ' threw exception: ' . $err );
				}
			}

			if ( $ok ) {
				self::record_history( $key, $payload, 'success', '', isset( $job['attempts'] ) ? (int) $job['attempts'] : 0, $type );
				unset( $q[ $key ] );
				++$done;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// Debug: error_log( '[MUMB_Queue] Job ' . $key . ' completed successfully. Done count: ' . $done );
				}
				if ( class_exists( 'MUMB_Logger' ) ) {
					MUMB_Logger::log( 'info', 'queue', 'Job success: ' . $type, array( 'key' => $key ) );
				}
			} else {
				$att      = (int) ( $job['attempts'] ?? 0 ) + 1;
				$delay    = min( 3600, 30 * ( 2 ** ( $att - 1 ) ) );
				$next_run = time() + $delay;
				if ( isset( $q[ $key ] ) && is_array( $q[ $key ] ) ) {
					$job_item               = &$q[ $key ];
					$job_item['attempts']   = $att;
					$job_item['next_run']   = $next_run;
					$job_item['last_error'] = $err;
					$job_item['status']     = $att >= self::max_attempts() ? 'failed' : 'retrying';
					$job_item['updated_at'] = time();
				}
				if ( class_exists( 'MUMB_Logger' ) ) {
					MUMB_Logger::log( 'error', 'queue', 'Job failed: ' . ( $type ?: $key ) . ' — ' . $err, array( 'attempts' => $att ) );
				}
				if ( $att >= self::max_attempts() ) {
					unset( $q[ $key ] );
					self::record_history( $key, $payload, 'failed', $err, $att, $type );
					self::notify_failure( $key, $payload, $err, $att );
				}
			}
		}
		SettingsService::set_job_queue( $q );
		try {
			if ( $done > 0 ) {
				( new MUMB_Sync_Service() )->sync_all();
			}
		} catch ( \Throwable $e ) {
			/* ignore */
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// Debug: error_log( '[MUMB_Queue] Process complete. Processed: ' . $done . ', Remaining: ' . count( $q ) );
		}

		return array(
			'processed' => $done,
			'remaining' => count( $q ),
		);
	}

	/**
	 * @return array{pending: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>}
	 */
	public static function get_queue_snapshot(): array {
		return array(
			'pending' => self::format_pending_jobs(),
			'history' => self::get_history(),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_history(): array {
		$history = SettingsService::get_job_history();
		return array_map(
			static function ( $row ) {
				if ( ! is_array( $row ) ) {
					return array();
				}
				$row['time'] = isset( $row['time'] ) && is_scalar( $row['time'] ) ? (string) $row['time'] : current_time( 'mysql' );
				return $row;
			},
			$history
		);
	}

	public static function retry_job( string $key, bool $from_history = false ): bool {
		$q   = SettingsService::get_job_queue();
		$now = time();
		if ( isset( $q[ $key ] ) && is_array( $q[ $key ] ) ) {
			$job_item               = &$q[ $key ];
			$job_item['attempts']   = 0;
			$job_item['next_run']   = $now;
			$job_item['status']     = 'pending';
			$job_item['last_error'] = '';
			$job_item['updated_at'] = $now;
			SettingsService::set_job_queue( $q );
			return true;
		}

		if ( ! $from_history ) {
			return false;
		}

		$history = self::get_history();
		foreach ( $history as $entry ) {
			if ( isset( $entry['key'] ) && $entry['key'] === $key && ! empty( $entry['payload'] ) && is_array( $entry['payload'] ) ) {
				$payload = $entry['payload'];
				// Ensure payload has required 'type' field
				if ( ! isset( $payload['type'] ) || ! is_string( $payload['type'] ) ) {
					$payload['type'] = 'retry_from_history';
				}
				self::enqueue( $key, $payload );
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function record_history( string $key, array $payload, string $status, string $message, int $attempts, string $type ): void {
		$history = SettingsService::get_job_history();
		$entry   = array(
			'key'      => $key,
			'type'     => $type,
			'status'   => $status,
			'message'  => $message,
			'attempts' => $attempts,
			'payload'  => $payload,
			'time'     => current_time( 'mysql' ),
		);
		array_unshift( $history, $entry );
		if ( count( $history ) > self::HISTORY_LIMIT ) {
			$history = array_slice( $history, 0, self::HISTORY_LIMIT );
		}
		SettingsService::set_job_history( $history );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function format_pending_jobs(): array {
		$queue = SettingsService::get_job_queue();
		if ( ! is_array( $queue ) ) {
			return array();
		}
		$out = array();
		foreach ( $queue as $key => $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}
			$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
			$out[]   = array(
				'key'        => $key,
				'type'       => isset( $payload['type'] ) && is_scalar( $payload['type'] ) ? (string) $payload['type'] : '',
				'payload'    => $payload,
				'attempts'   => isset( $job['attempts'] ) && is_numeric( $job['attempts'] ) ? (int) $job['attempts'] : 0,
				'status'     => isset( $job['status'] ) && is_scalar( $job['status'] ) ? (string) $job['status'] : 'pending',
				'next_run'   => isset( $job['next_run'] ) && is_numeric( $job['next_run'] ) ? (int) $job['next_run'] : time(),
				'last_error' => isset( $job['last_error'] ) && is_scalar( $job['last_error'] ) ? (string) $job['last_error'] : '',
				'created_at' => isset( $job['created_at'] ) && is_numeric( $job['created_at'] ) ? (int) $job['created_at'] : time(),
				'updated_at' => isset( $job['updated_at'] ) && is_numeric( $job['updated_at'] ) ? (int) $job['updated_at'] : time(),
			);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{ok: bool, error: string}
	 */
	private static function job_assign_profile( RouterClientInterface $client, array $payload ): array {
		$username = isset( $payload['user'] ) && is_scalar( $payload['user'] ) ? (string) $payload['user'] : ( isset( $payload['username'] ) && is_scalar( $payload['username'] ) ? (string) $payload['username'] : '' );
		$profile  = isset( $payload['profile'] ) && is_scalar( $payload['profile'] ) ? (string) $payload['profile'] : '';
		/** @var mixed $expires */
		$expires = isset( $payload['expires_at'] ) ? $payload['expires_at'] : null;

		if ( '' === $username || '' === $profile ) {
			return array(
				'ok'    => false,
				'error' => 'Missing user or profile',
			);
		}

		$res = $client->assignProfile( $username, $profile );
		if ( ! self::response_success( $res ) ) {
			$message = isset( $res['error_message'] ) && is_scalar( $res['error_message'] ) ? (string) $res['error_message'] : __( 'Failed to assign profile', 'mikrotik-user-manager-bridge' );
			return array(
				'ok'    => false,
				'error' => $message,
			);
		}

		if ( null !== $expires && '' !== $expires ) {
			$expiry = self::apply_expiry( $client, $username, $profile, $expires );
			if ( ! $expiry['ok'] ) {
				return $expiry;
			}
		}

		self::refresh_user_cache( $client, $username );
		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{ok: bool, error: string}
	 */
	private static function job_create_user( RouterClientInterface $client, array $payload ): array {
		$username = isset( $payload['user'] ) && is_scalar( $payload['user'] ) ? (string) $payload['user'] : ( isset( $payload['username'] ) && is_scalar( $payload['username'] ) ? (string) $payload['username'] : '' );
		if ( '' === $username ) {
			return array(
				'ok'    => false,
				'error' => 'Missing username',
			);
		}

		$data = array( 'username' => $username );
		if ( isset( $payload['password'] ) && $payload['password'] !== '' ) {
			$data['password'] = is_scalar( $payload['password'] ) ? (string) $payload['password'] : '';
		} elseif ( isset( $payload['data'] ) && is_array( $payload['data'] ) && isset( $payload['data']['password'] ) && $payload['data']['password'] !== '' ) {
			$data['password'] = is_scalar( $payload['data']['password'] ) ? (string) $payload['data']['password'] : '';
		}
		if ( isset( $payload['comment'] ) && $payload['comment'] !== '' ) {
			$data['comment'] = is_scalar( $payload['comment'] ) ? (string) $payload['comment'] : '';
		} elseif ( isset( $payload['data'] ) && is_array( $payload['data'] ) && isset( $payload['data']['comment'] ) && $payload['data']['comment'] !== '' ) {
			$data['comment'] = is_scalar( $payload['data']['comment'] ) ? (string) $payload['data']['comment'] : '';
		}
		if ( isset( $payload['profile'] ) && $payload['profile'] !== '' ) {
			$data['group'] = is_scalar( $payload['profile'] ) ? (string) $payload['profile'] : '';
		}

		$res = $client->createUser( $data );
		if ( ! self::response_success( $res ) ) {
			$message = isset( $res['error_message'] ) && is_scalar( $res['error_message'] ) ? (string) $res['error_message'] : __( 'Failed to create user', 'mikrotik-user-manager-bridge' );
			$code    = isset( $res['error_code'] ) && is_scalar( $res['error_code'] ) ? (string) $res['error_code'] : '';
			if ( stripos( $message, 'exists' ) === false && stripos( $code, 'exists' ) === false ) {
				return array(
					'ok'    => false,
					'error' => $message,
				);
			}
		}

		$updatePayload = array(
			'user'       => $username,
			'username'   => $username,
			'profile'    => isset( $payload['profile'] ) && is_scalar( $payload['profile'] ) ? (string) $payload['profile'] : '',
			'expires_at' => isset( $payload['expires_at'] ) ? $payload['expires_at'] : null,
		);
		if ( isset( $payload['password'] ) && $payload['password'] !== '' ) {
			self::job_update_user(
				$client,
				array(
					'username' => $username,
					'password' => is_scalar( $payload['password'] ) ? (string) $payload['password'] : '',
				)
			);
		}
		if ( $updatePayload['profile'] !== '' ) {
			$assign = self::job_assign_profile( $client, $updatePayload );
			if ( ! $assign['ok'] ) {
				return $assign;
			}
		} else {
			self::refresh_user_cache( $client, $username );
		}

		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{ok: bool, error: string}
	 */
	private static function job_update_user( RouterClientInterface $client, array $payload ): array {
		$username = isset( $payload['username'] ) && is_scalar( $payload['username'] ) ? (string) $payload['username'] : '';
		if ( '' === $username ) {
			return array(
				'ok'    => false,
				'error' => 'Missing username',
			);
		}
		$target = isset( $payload['rest_id'] ) && $payload['rest_id'] !== '' && is_scalar( $payload['rest_id'] ) ? (string) $payload['rest_id'] : ( isset( $payload['um_id'] ) && $payload['um_id'] !== '' && is_scalar( $payload['um_id'] ) ? (string) $payload['um_id'] : $username );

		$data = array();
		if ( isset( $payload['password'] ) && $payload['password'] !== '' ) {
			$data['password'] = is_scalar( $payload['password'] ) ? (string) $payload['password'] : '';
		}
		if ( array_key_exists( 'comment', $payload ) && $payload['comment'] !== null ) {
			$data['comment'] = is_scalar( $payload['comment'] ) ? (string) $payload['comment'] : '';
		}
		if ( isset( $payload['disabled'] ) ) {
			$data['disabled'] = ! empty( $payload['disabled'] ) ? 'true' : 'false';
		}

		if ( ! empty( $data ) ) {
			$res = $client->updateUser( $target, $data );
			if ( ! self::response_success( $res ) ) {
				$message = isset( $res['error_message'] ) && is_scalar( $res['error_message'] ) ? (string) $res['error_message'] : __( 'Failed to update user', 'mikrotik-user-manager-bridge' );
				return array(
					'ok'    => false,
					'error' => $message,
				);
			}
		}

		if ( isset( $payload['profile'] ) && $payload['profile'] !== '' ) {
			$assign = self::job_assign_profile(
				$client,
				array(
					'user'       => $username,
					'profile'    => is_scalar( $payload['profile'] ) ? (string) $payload['profile'] : '',
					'expires_at' => isset( $payload['expires_at'] ) ? $payload['expires_at'] : null,
				)
			);
			if ( ! $assign['ok'] ) {
				return $assign;
			}
		} elseif ( isset( $payload['expires_at'] ) && $payload['expires_at'] ) {
			$profile = self::get_user_profile_from_db( $username );
			if ( $profile ) {
				$expiry = self::apply_expiry( $client, $username, $profile, $payload['expires_at'] );
				if ( ! $expiry['ok'] ) {
					return $expiry;
				}
			}
		}

		self::refresh_user_cache( $client, $username );
		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{ok: bool, error: string}
	 */
	private static function job_disable_user( RouterClientInterface $client, array $payload ): array {
		$username = isset( $payload['user'] ) && is_scalar( $payload['user'] ) ? (string) $payload['user'] : ( isset( $payload['username'] ) && is_scalar( $payload['username'] ) ? (string) $payload['username'] : '' );
		if ( '' === $username ) {
			return array(
				'ok'    => false,
				'error' => 'Missing username',
			);
		}
		$target = isset( $payload['rest_id'] ) && $payload['rest_id'] !== '' && is_scalar( $payload['rest_id'] ) ? (string) $payload['rest_id'] : ( isset( $payload['data'] ) && is_array( $payload['data'] ) && isset( $payload['data']['rest_id'] ) && $payload['data']['rest_id'] !== '' && is_scalar( $payload['data']['rest_id'] ) ? (string) $payload['data']['rest_id'] : ( isset( $payload['um_id'] ) && $payload['um_id'] !== '' && is_scalar( $payload['um_id'] ) ? (string) $payload['um_id'] : ( isset( $payload['data'] ) && is_array( $payload['data'] ) && isset( $payload['data']['um_id'] ) && $payload['data']['um_id'] !== '' && is_scalar( $payload['data']['um_id'] ) ? (string) $payload['data']['um_id'] : $username ) ) );

		$res = $client->updateUser( $target, array( 'disabled' => 'true' ) );
		if ( ! self::response_success( $res ) ) {
			$message = isset( $res['error_message'] ) && is_scalar( $res['error_message'] ) ? (string) $res['error_message'] : __( 'Failed to disable user', 'mikrotik-user-manager-bridge' );
			return array(
				'ok'    => false,
				'error' => $message,
			);
		}

		if ( ! empty( $payload['remove_profiles'] ) || ( isset( $payload['data'] ) && is_array( $payload['data'] ) && ! empty( $payload['data']['remove_profiles'] ) ) ) {
			$client->removeAllProfiles( $username );
		}

		self::refresh_user_cache( $client, $username );
		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	private static function refresh_user_cache( RouterClientInterface $client, string $username ): void {
		global $wpdb;
		if ( ! ( $wpdb instanceof wpdb ) ) {
			return;
		}
		$p     = $wpdb->prefix;
		$users = $client->getUsers();
		if ( self::response_success( $users ) && isset( $users['data'] ) ) {
			$users_data = $users['data'];
			if ( is_array( $users_data ) ) {
				foreach ( $users_data as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$name = '';
					if ( isset( $row['username'] ) && is_scalar( $row['username'] ) ) {
						$name = (string) $row['username'];
					} elseif ( isset( $row['login'] ) && is_scalar( $row['login'] ) ) {
						$name = (string) $row['login'];
					} elseif ( isset( $row['name'] ) && is_scalar( $row['name'] ) ) {
						$name = (string) $row['name'];
					}
					if ( '' !== $name && strcasecmp( $name, $username ) === 0 ) {
						$wpdb->replace(
							$p . 'mikrotik_users',
							array(
								'username'   => $name,
								'um_id'      => isset( $row['.id'] ) && is_scalar( $row['.id'] ) ? (string) $row['.id'] : null,
								'rest_id'    => isset( $row['id'] ) && is_scalar( $row['id'] ) ? (string) $row['id'] : null,
								'profile'    => isset( $row['group'] ) && is_scalar( $row['group'] ) ? (string) $row['group'] : ( isset( $row['profile'] ) && is_scalar( $row['profile'] ) ? (string) $row['profile'] : null ),
								'comment'    => isset( $row['comment'] ) && is_scalar( $row['comment'] ) ? (string) $row['comment'] : '',
								'updated_at' => current_time( 'mysql' ),
							),
							array( '%s', '%s', '%s', '%s', '%s', '%s' )
						);
						break;
					}
				}
			}
		}

		$profiles = $client->getUserProfiles();
		if ( self::response_success( $profiles ) && isset( $profiles['data'] ) ) {
			$profiles_data = $profiles['data'];
			if ( is_array( $profiles_data ) ) {
				$rows = array();
				foreach ( $profiles_data as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$name = '';
					if ( isset( $row['user'] ) && is_scalar( $row['user'] ) ) {
						$name = (string) $row['user'];
					} elseif ( isset( $row['users'] ) && is_scalar( $row['users'] ) ) {
						$name = (string) $row['users'];
					} elseif ( isset( $row['name'] ) && is_scalar( $row['name'] ) ) {
						$name = (string) $row['name'];
					}
					if ( '' !== $name && strcasecmp( $name, $username ) === 0 ) {
						$rows[] = $row;
					}
				}
				if ( ! empty( $rows ) ) {
					$wpdb->delete( $p . 'mikrotik_user_profiles', array( 'username' => $username ), array( '%s' ) );
					foreach ( $rows as $row ) {
						$expires_mysql = null;
						if ( ! empty( $row['till-time'] ) ) {
							$till_time = is_scalar( $row['till-time'] ) ? (string) $row['till-time'] : '';
							$ts        = strtotime( $till_time );
							if ( $ts ) {
								$expires_mysql = gmdate( 'Y-m-d H:i:s', $ts );
							}
						}
						$wpdb->replace(
							$p . 'mikrotik_user_profiles',
							array(
								'username'   => $username,
								'profile'    => isset( $row['profile'] ) && is_scalar( $row['profile'] ) ? (string) $row['profile'] : '',
								'um_id'      => isset( $row['.id'] ) && is_scalar( $row['.id'] ) ? (string) $row['.id'] : null,
								'rest_id'    => isset( $row['id'] ) && is_scalar( $row['id'] ) ? (string) $row['id'] : null,
								'expires_at' => $expires_mysql,
								'updated_at' => current_time( 'mysql' ),
							),
							array( '%s', '%s', '%s', '%s', '%s', '%s' )
						);
					}
				}
			}
		}
	}

	/**
	 * @param mixed $expires
	 * @return array{ok: bool, error: string}
	 */
	private static function apply_expiry( RouterClientInterface $client, string $username, string $profile, $expires ): array {
		if ( null === $expires || '' === $expires ) {
			return array(
				'ok'    => true,
				'error' => '',
			);
		}
		// Router expiry management is unreliable; record intent for logging and return success so WP remains master.
		if ( class_exists( 'MUMB_Logger' ) ) {
			MUMB_Logger::log(
				'info',
				'queue',
				'Expiry update deferred to WooCommerce master',
				array(
					'user'    => $username,
					'profile' => $profile,
					'expires' => $expires,
				)
			);
		}
		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	private static function get_user_profile_from_db( string $username ): ?string {
		global $wpdb;
		if ( ! ( $wpdb instanceof wpdb ) ) {
			return null;
		}
		$p = $wpdb->prefix;
		/** @var string|null $query */
		$query = $wpdb->prepare( 'SELECT profile FROM ' . $p . 'mikrotik_user_profiles WHERE username=%s LIMIT 1', $username ); // @phpstan-ignore-line
		/** @var string|null $profile */
		$profile = $wpdb->get_var( $query );
		return is_string( $profile ) ? $profile : null;
	}

	/**
	 * @return array{host:string, port:int, username:string, password:string, ssl:bool, api_timeout:int, retry_max?:int, retry_base_ms?:int, retry_jitter_ms?:int, um_rest?:bool, um_rest_base?:string}|array{}
	 */
	private static function build_opts( MUMB_Settings $settings ): array {
		$host = $settings->host;
		$user = $settings->username;
		$pass = $settings->password;
		if ( '' === $host || '' === $user || '' === $pass ) {
			return array();
		}
		$ssl  = $settings->ssl;
		$port = $settings->port;
		if ( $port <= 0 ) {
			$port = $ssl ? 8729 : 8728;
		}
		$opts = array(
			'host'        => $host,
			'port'        => $port,
			'username'    => $user,
			'password'    => $pass,
			'ssl'         => $ssl,
			'api_timeout' => isset( $settings->api_timeout ) ? $settings->api_timeout : 8,
		);
		foreach ( array( 'retry_max', 'retry_base_ms', 'retry_jitter_ms' ) as $key ) {
			if ( isset( $settings->$key ) ) {
				$opts[ $key ] = (int) $settings->$key;
			}
		}
		if ( isset( $settings->um_rest ) && $settings->um_rest ) {
			$opts['um_rest'] = true;
		}
		if ( isset( $settings->um_rest_base ) && $settings->um_rest_base ) {
			$opts['um_rest_base'] = $settings->um_rest_base;
		}
		return $opts;
	}

	/**
	 * @param array<string, mixed> $res
	 */
	private static function response_success( array $res ): bool {
		return isset( $res['status'] ) && $res['status'] === 'ok';
	}
}
