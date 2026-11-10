<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUMB_Mikrotik_Health {
	public const CRON_HOOK = 'mumb_health_cron';

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
	}

	public static function run_cron(): void {
		$servers = MUMB_Server_Registry::get_servers();
		foreach ( $servers as $server ) {
			self::refresh_server( (int) $server['id'] );
		}
	}

	public static function refresh_server( int $server_id ): ?array {
		$server = MUMB_Server_Registry::get_server( $server_id );
		if ( ! $server ) {
			return null;
		}
		$results = self::collect_status( $server );
		MUMB_Server_Registry::update_status( $server_id, array(
			'status'         => $results['status'],
			'latency_ms'     => $results['latency_ms'],
			'active_users'   => $results['active_users'],
			'last_ping'      => $results['last_ping'],
			'uptime_seconds' => $results['uptime_seconds'],
			'cpu_load'       => $results['cpu_load'],
			'memory_usage'   => $results['memory_usage'],
			'bandwidth_bps'  => $results['bandwidth_bps'],
		) );
		MUMB_Server_Registry::log_status( $server_id, $results );
		return $results;
	}

	public static function collect_status( array $server ): array {
		$hostname = $server['hostname'] ?? '';
		$api_port = (int) ( $server['api_port'] ?? 8728 );
		$rest_port = (int) ( $server['rest_port'] ?? 443 );
		$latency        = null;
		$active_users   = null;
		$status         = MUMB_Server_Registry::STATUS_OFFLINE;
		$last_ping      = null;
		$uptime_seconds = null;
		$cpu_load       = null;
		$memory_usage   = null;
		$bandwidth_bps  = null;

		$ping = self::ping_server( $hostname );
		if ( $ping['reachable'] ) {
			$status    = MUMB_Server_Registry::STATUS_DEGRADED;
			$latency   = $ping['latency_ms'];
			$last_ping = $ping['timestamp'];
			$api = self::test_api( $hostname, $api_port );
			if ( $api['reachable'] ) {
				$status = MUMB_Server_Registry::STATUS_ONLINE;
				if ( null !== $api['latency_ms'] ) {
					$latency = null === $latency ? $api['latency_ms'] : min( $latency, $api['latency_ms'] );
				}
				$active_users   = $api['active_users'];
				$uptime_seconds = $api['uptime_seconds'];
				$cpu_load       = $api['cpu_load'];
				$memory_usage   = $api['memory_usage'];
				$bandwidth_bps  = $api['bandwidth_bps'];
			} else {
				$rest = self::test_rest( $hostname, $rest_port );
				if ( $rest['reachable'] ) {
					$status = MUMB_Server_Registry::STATUS_DEGRADED;
					if ( null !== $rest['latency_ms'] ) {
						$latency = null === $latency ? $rest['latency_ms'] : min( $latency, $rest['latency_ms'] );
					}
				}
			}
		}

		return array(
			'hostname'       => $hostname,
			'status'         => $status,
			'latency_ms'     => $latency,
			'active_users'   => $active_users,
			'last_ping'      => $last_ping,
			'uptime_seconds' => $uptime_seconds,
			'cpu_load'       => $cpu_load,
			'memory_usage'   => $memory_usage,
			'bandwidth_bps'  => $bandwidth_bps,
		);
	}

	public static function ping_server( string $hostname ): array {
		$hostname = trim( $hostname );
		if ( '' === $hostname ) {
			return array(
				'reachable'  => false,
				'latency_ms' => null,
				'timestamp'  => null,
			);
		}
		$latency = null;
		$reachable = false;
		$timestamp = current_time( 'mysql', true );
		if ( self::can_exec() ) {
			$escaped = escapeshellarg( $hostname );
			$flag    = ( 'Windows' === PHP_OS_FAMILY ) ? '-n 1 -w 2000' : '-c 1 -W 2';
			$command = sprintf( 'ping %s %s', $flag, $escaped );
			$start   = microtime( true );
			@exec( $command, $output, $code );
			$elapsed = ( microtime( true ) - $start ) * 1000;
			if ( 0 === (int) $code ) {
				$reachable = true;
				$latency   = (int) round( $elapsed );
			}
		}
		if ( ! $reachable ) {
			$socket = @fsockopen( $hostname, 80, $errno, $errstr, 2.0 );
			if ( $socket ) {
				stream_set_timeout( $socket, 2 );
				$start = microtime( true );
				fwrite( $socket, "HEAD / HTTP/1.1\r\nHost: {$hostname}\r\nConnection: Close\r\n\r\n" );
				fclose( $socket );
				$reachable = true;
				$latency   = (int) round( ( microtime( true ) - $start ) * 1000 );
			}
		}
		return array(
			'reachable'  => $reachable,
			'latency_ms' => $latency,
			'timestamp'  => $reachable ? $timestamp : null,
		);
	}

	public static function test_api( string $hostname, int $port ): array {
		$baseline = self::probe_socket( $hostname, $port );
		if ( '' === $hostname || $port <= 0 ) {
			return $baseline;
		}
		if ( ! class_exists( 'MUMB_Settings' ) ) {
			return $baseline;
		}
		$settings = MUMB_Settings::load();
		if ( ! $settings->is_configured() ) {
			return $baseline;
		}
		$password = $settings->decrypted_password();
		if ( '' === $password ) {
			return $baseline;
		}
		$ssl = $settings->ssl;
		if ( 8729 === $port ) {
			$ssl = true;
		} elseif ( 8728 === $port ) {
			$ssl = false;
		}
		$api = null;
		try {
			$api = new MUMB_RouterOS_API(
				$hostname,
				$port,
				$settings->username,
				$password,
				$ssl,
				$settings->api_timeout,
				$settings->verify_ssl
			);
			$start   = microtime( true );
			$login   = $api->login();
			$latency = (int) round( ( microtime( true ) - $start ) * 1000 );
			if ( empty( $login['success'] ) ) {
				return $baseline;
			}
			$metrics = self::collect_router_metrics_from_api( $api );
			$baseline['reachable']      = true;
			$baseline['latency_ms']     = null === $baseline['latency_ms'] ? $latency : min( $baseline['latency_ms'], $latency );
			$baseline['active_users']   = $metrics['active_users'];
			$baseline['uptime_seconds'] = $metrics['uptime_seconds'];
			$baseline['cpu_load']       = $metrics['cpu_load'];
			$baseline['memory_usage']   = $metrics['memory_usage'];
			$baseline['bandwidth_bps']  = $metrics['bandwidth_bps'];
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log(
					'warning',
					'router_metrics',
					'Router API metrics collection failed',
					array(
						'host'  => $hostname,
						'error' => $e->getMessage(),
					)
				);
			}
		} finally {
			if ( $api instanceof MUMB_RouterOS_API ) {
				$api->disconnect();
			}
		}
		return $baseline;
	}

	public static function test_rest( string $hostname, int $port ): array {
		if ( '' === $hostname || $port <= 0 ) {
			return array(
				'reachable' => false,
				'latency_ms' => null,
			);
		}
		$scheme = 443 === $port ? 'https' : 'http';
		$url    = sprintf( '%s://%s:%d/rest/user-manager', $scheme, $hostname, $port );
		$start  = microtime( true );
		$response = wp_remote_get( $url, array( 'timeout' => 3, 'sslverify' => false ) );
		if ( is_wp_error( $response ) ) {
			return array(
				'reachable' => false,
				'latency_ms' => null,
			);
		}
		$code = wp_remote_retrieve_response_code( $response );
		return array(
			'reachable' => $code >= 200 && $code < 500,
			'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
	}

	private static function probe_socket( string $hostname, int $port ): array {
		if ( '' === $hostname || $port <= 0 ) {
			return array(
				'reachable'      => false,
				'latency_ms'     => null,
				'active_users'   => null,
				'uptime_seconds' => null,
				'cpu_load'       => null,
				'memory_usage'   => null,
				'bandwidth_bps'  => null,
			);
		}
		$latency   = null;
		$reachable = false;
		$start     = microtime( true );
		$socket    = @fsockopen( $hostname, $port, $errno, $errstr, 2.5 );
		if ( $socket ) {
			$reachable = true;
			$latency   = (int) round( ( microtime( true ) - $start ) * 1000 );
			fclose( $socket );
		}
		return array(
			'reachable'      => $reachable,
			'latency_ms'     => $latency,
			'active_users'   => null,
			'uptime_seconds' => null,
			'cpu_load'       => null,
			'memory_usage'   => null,
			'bandwidth_bps'  => null,
		);
	}

	private static function collect_router_metrics_from_api( MUMB_RouterOS_API $api ): array {
		$metrics = array(
			'active_users'   => null,
			'uptime_seconds' => null,
			'cpu_load'       => null,
			'memory_usage'   => null,
			'bandwidth_bps'  => null,
		);
		$resource_rows = $api->print_resource( '/system/resource/print' );
		if ( ! empty( $resource_rows ) && isset( $resource_rows[0] ) && is_array( $resource_rows[0] ) ) {
			$resource = $resource_rows[0];
			if ( isset( $resource['uptime'] ) ) {
				$metrics['uptime_seconds'] = self::parse_duration_seconds( (string) $resource['uptime'] );
			}
			if ( null === $metrics['uptime_seconds'] && isset( $resource['uptime-seconds'] ) ) {
				$metrics['uptime_seconds'] = self::to_int( $resource['uptime-seconds'] );
			}
			if ( isset( $resource['cpu-load'] ) ) {
				$metrics['cpu_load'] = self::to_float( $resource['cpu-load'] );
			} elseif ( isset( $resource['cpu-load-percent'] ) ) {
				$metrics['cpu_load'] = self::to_float( $resource['cpu-load-percent'] );
			}
			$total_memory = null;
			$free_memory  = null;
			if ( isset( $resource['total-memory'] ) ) {
				$total_memory = self::to_int( $resource['total-memory'] );
			}
			if ( isset( $resource['free-memory'] ) ) {
				$free_memory = self::to_int( $resource['free-memory'] );
			}
			if ( null !== $total_memory && $total_memory > 0 && null !== $free_memory ) {
				$used                 = max( 0, $total_memory - $free_memory );
				$metrics['memory_usage'] = (float) round( ( $used / $total_memory ) * 100, 2 );
			}
		}
		$active = self::count_active_sessions( $api );
		if ( null !== $active ) {
			$metrics['active_users'] = $active;
		}
		return $metrics;
	}

	private static function count_active_sessions( MUMB_RouterOS_API $api ): ?int {
		$paths = array(
			'/tool/user-manager/session/print',
			'/user-manager/session/print',
			'/tool/user-manager/user/active/print',
			'/user-manager/user/active/print',
		);
		foreach ( $paths as $path ) {
			$rows = $api->print_resource( $path );
			if ( empty( $rows ) ) {
				continue;
			}
			$seen = array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( isset( $row['user'] ) ) {
					$seen[ strtolower( (string) $row['user'] ) ] = true;
				} elseif ( isset( $row['username'] ) ) {
					$seen[ strtolower( (string) $row['username'] ) ] = true;
				} else {
					$seen[] = true;
				}
			}
			if ( ! empty( $seen ) ) {
				return count( $seen );
			}
		}
		$rows = $api->print_resource( '/tool/user-manager/user/print' );
		if ( empty( $rows ) ) {
			$rows = $api->print_resource( '/user-manager/user/print' );
		}
		if ( empty( $rows ) ) {
			return null;
		}
		$count = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['uptime'] ) ) {
				$seconds = self::parse_duration_seconds( (string) $row['uptime'] );
				if ( null !== $seconds && $seconds > 0 ) {
					++$count;
					continue;
				}
			}
			if ( isset( $row['active'] ) && self::string_to_bool( (string) $row['active'] ) ) {
				++$count;
				continue;
			}
			if ( isset( $row['disabled'] ) && self::string_to_bool( (string) $row['disabled'] ) ) {
				continue;
			}
			if ( isset( $row['status'] ) && self::string_to_bool( (string) $row['status'] ) ) {
				++$count;
				continue;
			}
			if ( isset( $row['last-seen'] ) && '' !== trim( (string) $row['last-seen'] ) ) {
				++$count;
			}
		}
		return $count > 0 ? $count : null;
	}

	private static function parse_duration_seconds( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$day    = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$hour   = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
		$week   = defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : ( 7 * $day );
		if ( preg_match( '/^(?:(\d+)d)?\s*(\d{1,2}):(\d{2}):(\d{2})$/', $value, $parts ) ) {
			$days = isset( $parts[1] ) && '' !== $parts[1] ? (int) $parts[1] : 0;
			$hours  = (int) $parts[2];
			$minutes = (int) $parts[3];
			$seconds = (int) $parts[4];
			return ( $days * $day ) + ( $hours * $hour ) + ( $minutes * $minute ) + $seconds;
		}
		$total = 0;
		if ( preg_match_all( '/(\d+)(w|d|h|m|s)/', $value, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$amount = (int) $match[1];
				switch ( $match[2] ) {
					case 'w':
						$total += $amount * $week;
						break;
					case 'd':
						$total += $amount * $day;
						break;
					case 'h':
						$total += $amount * $hour;
						break;
					case 'm':
						$total += $amount * $minute;
						break;
					case 's':
						$total += $amount;
						break;
				}
			}
			return $total;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		return null;
	}

	private static function to_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		if ( is_string( $value ) ) {
			$filtered = preg_replace( '/[^0-9\-]/', '', $value );
			if ( is_string( $filtered ) && $filtered !== '' && '-' !== $filtered && is_numeric( $filtered ) ) {
				return (int) $filtered;
			}
		}
		return null;
	}

	private static function to_float( mixed $value ): ?float {
		if ( is_float( $value ) || is_int( $value ) ) {
			return (float) $value;
		}
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
		if ( is_string( $value ) ) {
			$filtered = preg_replace( '/[^0-9\-.]/', '', $value );
			if ( is_string( $filtered ) && $filtered !== '' && '-' !== $filtered && '.' !== $filtered && is_numeric( $filtered ) ) {
				return (float) $filtered;
			}
		}
		return null;
	}

	private static function string_to_bool( string $value ): bool {
		$value = strtolower( trim( $value ) );
		return in_array( $value, array( '1', 'true', 'yes', 'enabled', 'active', 'online' ), true );
	}

	private static function can_exec(): bool {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = explode( ',', (string) ini_get( 'disable_functions' ) );
		return ! in_array( 'exec', array_map( 'trim', $disabled ), true );
	}
}
