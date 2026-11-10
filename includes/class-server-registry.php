<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUMB_Server_Registry {

	public const ROLE_USERMANAGER = 'usermanager';
	public const ROLE_VPN         = 'vpnnode';

	public const STATUS_ONLINE   = 'online';
	public const STATUS_DEGRADED = 'degraded';
	public const STATUS_OFFLINE  = 'offline';

	/**
	 * Ensure the server registry table exists.
	 */
	public static function create_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$sql = "CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(190) NOT NULL,
				hostname VARCHAR(190) NOT NULL,
				role VARCHAR(32) NOT NULL DEFAULT '" . self::ROLE_USERMANAGER . "',
				api_port INT UNSIGNED NOT NULL DEFAULT 8728,
				rest_port INT UNSIGNED NOT NULL DEFAULT 443,
				status VARCHAR(32) NOT NULL DEFAULT '" . self::STATUS_OFFLINE . "',
				last_ping DATETIME NULL,
				latency_ms INT NULL,
				active_users INT NULL,
				uptime_seconds BIGINT NULL,
				cpu_load DECIMAL(6,2) NULL,
				memory_usage INT NULL,
				bandwidth_bps BIGINT NULL,
				notes TEXT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY idx_role (role),
				KEY idx_status (status),
				KEY idx_hostname (hostname)
			) {$charset};";
			dbDelta( $sql );
			self::create_logs_table();
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mikrotik_servers';
	}

	public static function logs_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mikrotik_server_logs';
	}

	private static function create_logs_table(): void {
		global $wpdb;
		$table   = self::logs_table_name();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			server_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(32) NOT NULL,
			latency_ms INT NULL,
			active_users INT NULL,
			uptime_seconds BIGINT NULL,
			cpu_load DECIMAL(6,2) NULL,
			memory_usage INT NULL,
			bandwidth_bps BIGINT NULL,
			recorded_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_server_time (server_id, recorded_at)
		) {$charset};";
		dbDelta( $sql );
	}

	public static function get_servers( array $args = array() ): array {
		global $wpdb;
		$defaults = array(
			'role'    => null,
			'status'  => null,
			'orderby' => 'name',
			'order'   => 'ASC',
		);
		$args     = wp_parse_args( $args, $defaults );
		$table    = self::table_name();
		$where    = array();
		$params   = array();
		if ( $args['role'] ) {
			$where[]  = 'role = %s';
			$params[] = self::sanitize_role( $args['role'] );
		}
		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = self::sanitize_status( $args['status'] );
		}
		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$orderby   = self::sanitize_orderby( $args['orderby'] );
		$order     = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$sql       = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order}";
		if ( $params ) {
			$query = $wpdb->prepare( $sql, $params );
		} else {
			$query = $sql;
		}
		/** @var array<int, array<string, mixed>> $rows */
		$rows = $wpdb->get_results( $query, ARRAY_A ) ?: array();
		return array_map( array( __CLASS__, 'format_row' ), $rows );
	}

	public static function get_server( int $id ): ?array {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return self::format_row( $row );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$table = self::table_name();
		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		return (bool) $deleted;
	}

	public static function upsert( array $data ): int {
		global $wpdb;
		$table = self::table_name();
		$prepared = self::prepare_payload( $data );
		$fields   = $prepared['data'];
		$formats  = $prepared['format'];
		if ( isset( $data['id'] ) && $data['id'] ) {
			$wpdb->update( $table, $fields, array( 'id' => (int) $data['id'] ), $formats, array( '%d' ) );
			return (int) $data['id'];
		}
		$wpdb->insert( $table, $fields, $formats );
		return (int) $wpdb->insert_id;
	}

	public static function update_status( int $id, array $status ): void {
		global $wpdb;
		$table        = self::table_name();
		$set_clauses  = array();
		$params       = array();
		if ( isset( $status['status'] ) ) {
			$set_clauses[] = 'status = %s';
			$params[]      = self::sanitize_status( (string) $status['status'] );
		}
		self::prepare_numeric_update( $status, 'latency_ms', 'latency_ms', $set_clauses, $params );
		self::prepare_numeric_update( $status, 'active_users', 'active_users', $set_clauses, $params );
		self::prepare_numeric_update( $status, 'uptime_seconds', 'uptime_seconds', $set_clauses, $params );
		self::prepare_numeric_update( $status, 'cpu_load', 'cpu_load', $set_clauses, $params, '%f' );
		self::prepare_numeric_update( $status, 'memory_usage', 'memory_usage', $set_clauses, $params );
		self::prepare_numeric_update( $status, 'bandwidth_bps', 'bandwidth_bps', $set_clauses, $params );
		if ( array_key_exists( 'last_ping', $status ) ) {
			if ( null === $status['last_ping'] ) {
				$set_clauses[] = 'last_ping = NULL';
			} else {
				$set_clauses[] = 'last_ping = %s';
				$params[]      = gmdate( 'Y-m-d H:i:s', strtotime( (string) $status['last_ping'] ) );
			}
		}
		if ( empty( $set_clauses ) ) {
			return;
		}
		$params[] = $id;
		$query    = 'UPDATE ' . $table . ' SET ' . implode( ', ', $set_clauses ) . ' WHERE id = %d';
		$wpdb->query( $wpdb->prepare( $query, $params ) );
	}

	/**
	 * Helper to append numeric update clauses while handling NULL values.
	 *
	 * @param array<string, mixed> $status
	 * @param string               $key
	 * @param string               $column
	 * @param array<int, string>   $clauses
	 * @param array<int, mixed>    $params
	 */
	private static function prepare_numeric_update( array $status, string $key, string $column, array &$clauses, array &$params, string $format = '%d' ): void {
		if ( ! array_key_exists( $key, $status ) ) {
			return;
		}
		$value = $status[ $key ];
		if ( null === $value || '' === $value ) {
			$clauses[] = $column . ' = NULL';
			return;
		}
		$clauses[] = $column . ' = ' . $format;
		$params[]  = ( '%f' === $format ) ? (float) $value : (int) $value;
	}

	public static function log_status( int $server_id, array $status ): void {
		global $wpdb;
		$table = self::logs_table_name();
		$data  = array(
			'server_id'      => (int) $server_id,
			'status'         => self::sanitize_status( (string) ( $status['status'] ?? self::STATUS_OFFLINE ) ),
			'latency_ms'     => array_key_exists( 'latency_ms', $status ) ? ( null === $status['latency_ms'] ? null : (int) $status['latency_ms'] ) : null,
			'active_users'   => array_key_exists( 'active_users', $status ) ? ( null === $status['active_users'] ? null : (int) $status['active_users'] ) : null,
			'uptime_seconds' => array_key_exists( 'uptime_seconds', $status ) ? ( null === $status['uptime_seconds'] ? null : (int) $status['uptime_seconds'] ) : null,
			'cpu_load'       => array_key_exists( 'cpu_load', $status ) ? ( null === $status['cpu_load'] ? null : (float) $status['cpu_load'] ) : null,
			'memory_usage'   => array_key_exists( 'memory_usage', $status ) ? ( null === $status['memory_usage'] ? null : (int) $status['memory_usage'] ) : null,
			'bandwidth_bps'  => array_key_exists( 'bandwidth_bps', $status ) ? ( null === $status['bandwidth_bps'] ? null : (int) $status['bandwidth_bps'] ) : null,
			'recorded_at'    => gmdate( 'Y-m-d H:i:s' ),
		);
		$formats = array( '%d', '%s', '%d', '%d', '%d', '%f', '%d', '%d', '%s' );
		$keys    = array( 'latency_ms', 'active_users', 'uptime_seconds', 'cpu_load', 'memory_usage', 'bandwidth_bps' );
		foreach ( $keys as $index => $key ) {
			$position = $index + 2;
			if ( null === $data[ $key ] ) {
				$formats[ $position ] = '%s';
			}
		}
		$wpdb->insert( $table, $data, $formats );
		self::prune_logs( $server_id );
	}

	public static function get_server_history( int $server_id, int $limit = 20 ): array {
		global $wpdb;
		$limit = max( 1, $limit );
		$table = self::logs_table_name();
		$sql   = $wpdb->prepare(
			"SELECT id, server_id, status, latency_ms, active_users, uptime_seconds, cpu_load, memory_usage, bandwidth_bps, recorded_at
			FROM {$table}
			WHERE server_id = %d
			ORDER BY recorded_at DESC
			LIMIT %d",
			$server_id,
			$limit
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map(
			function ( array $row ): array {
				$row['server_id']      = (int) $row['server_id'];
				$row['latency_ms']     = isset( $row['latency_ms'] ) ? ( null === $row['latency_ms'] ? null : (int) $row['latency_ms'] ) : null;
				$row['active_users']   = isset( $row['active_users'] ) ? ( null === $row['active_users'] ? null : (int) $row['active_users'] ) : null;
				$row['uptime_seconds'] = isset( $row['uptime_seconds'] ) ? ( null === $row['uptime_seconds'] ? null : (int) $row['uptime_seconds'] ) : null;
				$row['cpu_load']       = isset( $row['cpu_load'] ) ? ( null === $row['cpu_load'] ? null : (float) $row['cpu_load'] ) : null;
				$row['memory_usage']   = isset( $row['memory_usage'] ) ? ( null === $row['memory_usage'] ? null : (int) $row['memory_usage'] ) : null;
				$row['bandwidth_bps']  = isset( $row['bandwidth_bps'] ) ? ( null === $row['bandwidth_bps'] ? null : (int) $row['bandwidth_bps'] ) : null;
				$row['status']         = self::sanitize_status( $row['status'] ?? self::STATUS_OFFLINE );
				return $row;
			},
			$rows
		);
	}

	private static function prune_logs( int $server_id, int $keep = 50 ): void {
		global $wpdb;
		$table = self::logs_table_name();
		$keep  = max( 1, $keep );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table}
			WHERE server_id = %d
			AND id NOT IN (
				SELECT id FROM (
					SELECT id FROM {$table} WHERE server_id = %d ORDER BY recorded_at DESC LIMIT %d
				) prune
			)",
			$server_id,
			$server_id,
			$keep
		) );
	}

	private static function prepare_payload( array $data ): array {
		$defaults = array(
			'name'           => '',
			'hostname'       => '',
			'role'           => self::ROLE_USERMANAGER,
			'api_port'       => 8728,
			'rest_port'      => 443,
			'notes'          => '',
			'status'         => self::STATUS_OFFLINE,
			'last_ping'      => null,
			'latency_ms'     => null,
			'active_users'   => null,
			'uptime_seconds' => null,
			'cpu_load'       => null,
			'memory_usage'   => null,
			'bandwidth_bps'  => null,
		);
		$data = wp_parse_args( $data, $defaults );
		$payload = array(
			'name'      => sanitize_text_field( $data['name'] ),
			'hostname'  => sanitize_text_field( $data['hostname'] ),
			'role'      => self::sanitize_role( (string) $data['role'] ),
			'api_port'  => self::sanitize_port( $data['api_port'], 8728 ),
			'rest_port' => self::sanitize_port( $data['rest_port'], 443 ),
			'notes'     => wp_kses_post( $data['notes'] ),
			'status'    => self::sanitize_status( (string) $data['status'] ),
		);
		$formats = array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' );
		if ( ! empty( $data['last_ping'] ) ) {
			$payload['last_ping'] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $data['last_ping'] ) );
			$formats[]            = '%s';
		}
		if ( null !== $data['latency_ms'] && $data['latency_ms'] !== '' ) {
			$payload['latency_ms'] = (int) $data['latency_ms'];
			$formats[]             = '%d';
		}
		if ( null !== $data['active_users'] && $data['active_users'] !== '' ) {
			$payload['active_users'] = (int) $data['active_users'];
			$formats[]               = '%d';
		}
		if ( null !== $data['uptime_seconds'] && $data['uptime_seconds'] !== '' ) {
			$payload['uptime_seconds'] = (int) $data['uptime_seconds'];
			$formats[]                 = '%d';
		}
		if ( null !== $data['cpu_load'] && $data['cpu_load'] !== '' ) {
			$payload['cpu_load'] = (float) $data['cpu_load'];
			$formats[]           = '%f';
		}
		if ( null !== $data['memory_usage'] && $data['memory_usage'] !== '' ) {
			$payload['memory_usage'] = (int) $data['memory_usage'];
			$formats[]               = '%d';
		}
		if ( null !== $data['bandwidth_bps'] && $data['bandwidth_bps'] !== '' ) {
			$payload['bandwidth_bps'] = (int) $data['bandwidth_bps'];
			$formats[]                = '%d';
		}
		return array(
			'data'   => $payload,
			'format' => $formats,
		);
	}

	private static function sanitize_role( string $role ): string {
		$role = strtolower( $role );
		return in_array( $role, array( self::ROLE_USERMANAGER, self::ROLE_VPN ), true ) ? $role : self::ROLE_USERMANAGER;
	}

	private static function sanitize_status( string $status ): string {
		$status = strtolower( $status );
		if ( in_array( $status, array( self::STATUS_ONLINE, self::STATUS_DEGRADED, self::STATUS_OFFLINE ), true ) ) {
			return $status;
		}
		return self::STATUS_OFFLINE;
	}

	private static function sanitize_port( $port, int $default ): int {
		if ( is_numeric( $port ) ) {
			$port = (int) $port;
			if ( $port > 0 && $port <= 65535 ) {
				return $port;
			}
		}
		return $default;
	}

	private static function sanitize_orderby( string $orderby ): string {
		$allowed = array( 'name', 'hostname', 'role', 'status', 'last_ping', 'latency_ms', 'active_users', 'uptime_seconds', 'cpu_load', 'memory_usage', 'bandwidth_bps' );
		return in_array( $orderby, $allowed, true ) ? $orderby : 'name';
	}

	private static function format_row( array $row ): array {
		$row['id']           = (int) $row['id'];
		$row['api_port']     = isset( $row['api_port'] ) ? (int) $row['api_port'] : 8728;
		$row['rest_port']    = isset( $row['rest_port'] ) ? (int) $row['rest_port'] : 443;
		$row['latency_ms']   = isset( $row['latency_ms'] ) ? ( null === $row['latency_ms'] ? null : (int) $row['latency_ms'] ) : null;
		$row['active_users'] = isset( $row['active_users'] ) ? ( null === $row['active_users'] ? null : (int) $row['active_users'] ) : null;
		$row['uptime_seconds'] = isset( $row['uptime_seconds'] ) ? ( null === $row['uptime_seconds'] ? null : (int) $row['uptime_seconds'] ) : null;
		$row['cpu_load']       = isset( $row['cpu_load'] ) ? ( null === $row['cpu_load'] ? null : (float) $row['cpu_load'] ) : null;
		$row['memory_usage']   = isset( $row['memory_usage'] ) ? ( null === $row['memory_usage'] ? null : (int) $row['memory_usage'] ) : null;
		$row['bandwidth_bps']  = isset( $row['bandwidth_bps'] ) ? ( null === $row['bandwidth_bps'] ? null : (int) $row['bandwidth_bps'] ) : null;
		$row['status']       = self::sanitize_status( $row['status'] ?? self::STATUS_OFFLINE );
		$row['role']         = self::sanitize_role( $row['role'] ?? self::ROLE_USERMANAGER );
		return $row;
	}
}
