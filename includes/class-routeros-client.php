<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RouterOS binary API client with basic UM helpers.
 */
class MUMB_RouterOS_API {

	protected string $host;
	protected int $port;
	protected string $username;
	protected string $password;
	protected bool $ssl;
	protected int $timeout;
	protected bool $verify_peer;
	/** @var resource|null */
	protected $socket             = null;
	protected ?string $last_error = null;

	public function __construct( string $host, int $port, string $username, string $password, bool $ssl = false, int $timeout = 8, bool $verify_ssl = true ) {
		$this->host        = $host;
		$this->port        = $port ?: ( $ssl ? 8729 : 8728 );
		$this->username    = $username;
		$this->password    = $password;
		$this->ssl         = $ssl;
		$this->timeout     = $timeout > 0 ? $timeout : 8;
		$this->verify_peer = $verify_ssl;
		// Use system default CA bundle (no custom ca_path)
	}

	public function __destruct() {
		$this->disconnect();
	}

	public function connect( int $timeout = null ): bool {
		$scheme = $this->ssl ? 'ssl' : 'tcp';
		$remote = sprintf( '%s://%s:%d', $scheme, $this->host, $this->port );
		if ( $this->ssl ) {
			$ssl_options = array();
			if ( $this->verify_peer ) {
				$ssl_options['verify_peer']      = true;
				$ssl_options['verify_peer_name'] = true;
				// Use system default CA bundle
			} else {
				$ssl_options['verify_peer']       = false;
				$ssl_options['verify_peer_name']  = false;
				$ssl_options['allow_self_signed'] = true;
			}
			$ctx = stream_context_create( array( 'ssl' => $ssl_options ) );
		} else {
			$ctx = stream_context_create( array() );
		}
		$errno  = 0;
		$errstr = '';
		$to     = null !== $timeout ? $timeout : $this->timeout;
		$socket = @stream_socket_client( $remote, $errno, $errstr, $to, STREAM_CLIENT_CONNECT, $ctx );
		if ( ! is_resource( $socket ) ) {
			$this->last_error = sprintf( '%s (host=%s port=%d ssl=%s errno=%d)', $errstr ?: 'connect failed', $this->host, $this->port, $this->ssl ? 'yes' : 'no', $errno );
			$this->socket     = null;
			if ( $this->ssl && $this->verify_peer && class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log(
					'error',
					'router_ssl',
					'Router SSL verification failed',
					array(
						'host'  => $this->host,
						'port'  => $this->port,
						'error' => $this->last_error,
					)
				);
			}
			return false;
		}
		$this->socket = $socket;
		stream_set_timeout( $this->socket, $to );
		return true;
	}

	public function disconnect(): void {
		if ( is_resource( $this->socket ) ) {
			fclose( $this->socket );
		}
		$this->socket = null;
	}

	/**
	 * @return array{success:bool, message?:string}
	 */
	public function test(): array {
		if ( ! $this->connect() ) {
			return array(
				'success' => false,
				'message' => 'Socket connect failed: ' . ( $this->last_error ?? '' ),
			);
		}
		$res = $this->login();
		$this->disconnect();
		return $res;
	}

	// ---- Protocol I/O ----
	protected function write_len( int $len ): void {
		if ( $len < 0x80 ) {
			$this->write_bytes( chr( $len ) );
			return;
		}
		if ( $len < 0x4000 ) {
			$len |= 0x8000;
			$this->write_bytes( chr( ( $len >> 8 ) & 0xff ) . chr( $len & 0xff ) );
			return;
		}
		if ( $len < 0x200000 ) {
			$len |= 0xC00000;
			$this->write_bytes( chr( ( $len >> 16 ) & 0xff ) . chr( ( $len >> 8 ) & 0xff ) . chr( $len & 0xff ) );
			return;
		}
		if ( $len < 0x10000000 ) {
			$len |= 0xE0000000;
			$this->write_bytes( chr( ( $len >> 24 ) & 0xff ) . chr( ( $len >> 16 ) & 0xff ) . chr( ( $len >> 8 ) & 0xff ) . chr( $len & 0xff ) );
			return;
		}
		$this->write_bytes( chr( 0xF0 ) . chr( ( $len >> 24 ) & 0xff ) . chr( ( $len >> 16 ) & 0xff ) . chr( ( $len >> 8 ) & 0xff ) . chr( $len & 0xff ) );
	}
	protected function read_len(): int {
		$c = $this->read_bytes( 1 );
		if ( '' === $c ) {
			return 0;
		} $len = ord( $c );
		if ( $len < 0x80 ) {
			return $len;
		}
		if ( $len < 0xC0 ) {
			$c2 = ord( $this->read_bytes( 1 ) );
			return ( ( $len & 0x7F ) << 8 ) + $c2;
		}
		if ( $len < 0xE0 ) {
			$c2 = ord( $this->read_bytes( 1 ) );
			$c3 = ord( $this->read_bytes( 1 ) );
			return ( ( $len & 0x3F ) << 16 ) + ( $c2 << 8 ) + $c3;
		}
		if ( $len < 0xF0 ) {
			$c2 = ord( $this->read_bytes( 1 ) );
			$c3 = ord( $this->read_bytes( 1 ) );
			$c4 = ord( $this->read_bytes( 1 ) );
			return ( ( $len & 0x1F ) << 24 ) + ( $c2 << 16 ) + ( $c3 << 8 ) + $c4;
		}
		if ( $len == 0xF0 ) {
			$c2 = ord( $this->read_bytes( 1 ) );
			$c3 = ord( $this->read_bytes( 1 ) );
			$c4 = ord( $this->read_bytes( 1 ) );
			$c5 = ord( $this->read_bytes( 1 ) );
			return ( $c2 << 24 ) + ( $c3 << 16 ) + ( $c4 << 8 ) + $c5;
		}
		return 0;
	}
	protected function write_bytes( string $data ): void {
		if ( ! is_resource( $this->socket ) ) {
			return;
		} $off = 0;
		$len   = strlen( $data );
		while ( $off < $len ) {
			$n = @fwrite( $this->socket, substr( $data, $off ) );
			if ( false === $n ) {
				break;
			} $off += $n;
		}
	}
	protected function read_bytes( int $len ): string {
		if ( ! is_resource( $this->socket ) ) {
			return '';
		}
		$out = '';
		while ( strlen( $out ) < $len ) {
			$remaining = $len - strlen( $out );
			if ( $remaining <= 0 ) {
				break;
			}
			$chunk = @fread( $this->socket, $remaining );
			if ( '' === $chunk || false === $chunk ) {
				break;
			} $out .= $chunk;
			$meta   = stream_get_meta_data( $this->socket );
			if ( ! empty( $meta['timed_out'] ) ) {
				break;
			}
		}
		return $out;
	}
	protected function write_word( string $w ): void {
		$this->write_len( strlen( $w ) );
		$this->write_bytes( $w );
	}
	protected function read_word(): ?string {
		$len = $this->read_len();
		if ( $len === 0 ) {
			return '';
		} return $len > 0 ? $this->read_bytes( $len ) : '';
	}
	/**
	 * @param array<int, string> $words
	 */
	protected function write_sentence( array $words ): void {
		foreach ( $words as $w ) {
			$this->write_word( $w );
		} $this->write_word( '' );
	}

	/**
	 * @return array<int, string>
	 */
	protected function read_sentence(): array {
		$res = array();
		while ( true ) {
			$w = $this->read_word();
			if ( '' === $w || null === $w ) {
				break;
			} $res[] = $w;
		} return $res;
	}

	/**
	 * @param array<int, array<int, string>> $replies
	 * @return array{success:bool, message?:string}
	 */
	protected function status_from( array $replies ): array {
		$last = end( $replies );
		$last = $last !== false ? $last : array();
		if ( isset( $last[0] ) && $last[0] === '!done' ) {
			return array(
				'success' => true,
				'message' => 'OK',
			);
		}
		if ( isset( $last[0] ) && ( $last[0] === '!trap' || $last[0] === '!fatal' ) ) {
			$msg = '';
			foreach ( $last as $w ) {
				if ( isset( $w[0] ) && $w[0] === '=' && substr( $w, 0, 9 ) === '=message=' ) {
					$msg = substr( $w, 9 );
					break;
				}
			}
			return array(
				'success' => false,
				'message' => $msg ?: 'Error',
			);
		}
		return array(
			'success' => false,
			'message' => 'Unexpected response',
		);
	}

	/**
	 * @param array<int, string> $words
	 * @return array<int, array<int, string>>
	 */
	public function talk( array $words ): array {
		$this->write_sentence( $words );
		$out = array();
		while ( true ) {
			$s = $this->read_sentence();
			if ( empty( $s ) ) {
				break;
			} $out[] = $s;
			if ( isset( $s[0] ) && ( $s[0] === '!done' || $s[0] === '!trap' || $s[0] === '!fatal' ) ) {
				break;
			}
		} return $out;
	}

	/**
	 * @param array<int, string> $words
	 * @return array{success:bool, replies?:array<int, array<int, string>>, message?:string}
	 */
	/**
	 * @param array<int, string> $words
	 * @return array{success:bool, message?:string, replies?:array<int, array<int, string>>}
	 */
	public function request( array $words ): array {
		if ( ! is_resource( $this->socket ) ) {
			$lg = $this->login();
			if ( empty( $lg['success'] ) ) {
				return $lg;
			}
		}
		$rep = $this->talk( $words );
		$st  = $this->status_from( $rep );
		if ( ! empty( $st['success'] ) ) {
			return array(
				'success' => true,
				'replies' => $rep,
			);
		}
		return $st;
	}

	/**
	 * Try v7 /user-manager first, then legacy /tool/user-manager; return first success.
	 */
	/**
	 * @param array<int, string> $legacy_words
	 * @param array<int, string> $v7_words
	 * @return array{success:bool, message?:string, replies?:array<int, array<int, string>>}
	 */
	protected function um_request_dual( array $legacy_words, array $v7_words ): array {
		$primary = $this->request( $v7_words );
		if ( $this->is_success( $primary ) ) {
			return $primary;
		}
		$message_lower = null;
		if ( isset( $primary['message'] ) ) {
			/** @var mixed $raw */
			$raw = $primary['message'];
			if ( is_string( $raw ) ) {
				$message_lower = strtolower( $raw );
			}
		}
		$legacy = $this->request( $legacy_words );
		if ( $this->is_success( $legacy ) ) {
			return $legacy;
		}
		if ( null !== $message_lower ) {
			if ( strpos( $message_lower, 'no such command' ) !== false || strpos( $message_lower, 'prefix' ) !== false || strpos( $message_lower, 'failure' ) !== false ) {
				return $primary;
			}
		}
		return $primary;
	}

	/**
	 * @return array{success:bool, message?:string}
	 */
	public function login(): array {
		if ( ! is_resource( $this->socket ) && ! $this->connect() ) {
			return array(
				'success' => false,
				'message' => 'Connect failed',
			);
		}
		// Try new style
		$rep = $this->talk( array( '/login', '=name=' . $this->username, '=password=' . $this->password ) );
		$st  = $this->status_from( $rep );
		if ( ! empty( $st['success'] ) ) {
			return $st;
		}
		// Legacy
		$chal = $this->talk( array( '/login' ) );
		$rows = $this->parse_rows( $chal );
		$ret  = '';
		foreach ( $rows as $r ) {
			if ( isset( $r['ret'] ) ) {
				$ret = $r['ret'];
				break;
			}
		}
		if ( ! $ret ) {
			return array(
				'success' => false,
				'message' => 'Login failed (no challenge)',
			);
		}
		$hash = md5( chr( 0 ) . $this->password . hex2bin( $ret ) );
		$resp = '00' . $hash;
		$rep2 = $this->talk( array( '/login', '=name=' . $this->username, '=response=' . $resp ) );
		return $this->status_from( $rep2 );
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	public function print_resource( string $path ): array {
		if ( ! is_resource( $this->socket ) ) {
			$login = $this->login();
			if ( empty( $login['success'] ) ) {
				return array();
			}
		}
		$res = $this->request( array( $path ) );
		if ( ! $this->is_success( $res ) ) {
			return array();
		}
		if ( ! array_key_exists( 'replies', $res ) ) {
			return array();
		}
		$replies = $res['replies'];
		if ( ! is_array( $replies ) ) {
			return array();
		}
		/** @var array<int, array<int, string>> $replies */
		return $this->parse_rows( $replies );
	}

	/**
	 * @param array<int, array<int, string>> $replies
	 * @return array<int, array<string, string>>
	 */
	protected function parse_rows( array $replies ): array {
		$rows = array();
		foreach ( $replies as $sentence ) {
			if ( empty( $sentence ) || $sentence[0] !== '!re' ) {
				continue;
			}
			$row = array();
			foreach ( $sentence as $w ) {
				if ( $w === '!re' ) {
					continue;
				}
				if ( '' !== $w && $w[0] === '=' ) {
					$pos = strpos( $w, '=', 1 );
					if ( $pos !== false ) {
						$key         = substr( $w, 1, $pos - 1 );
						$val         = substr( $w, $pos + 1 );
						$row[ $key ] = $val;
					}
				}
			}
			if ( $row ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function is_success( array $response ): bool {
		return ! empty( $response['success'] );
	}

	// ---- UM helpers ----
	/**
	 * @return array<int, array<string, string>>
	 */
	public function um_list_users(): array {
		// Try v7 path first (RouterOS 7.x), then fall back to v6
		$rows = $this->print_resource( '/user-manager/user/print' );
		if ( empty( $rows ) ) {
			$rows = $this->print_resource( '/tool/user-manager/user/print' );
		}
		return $rows;
	}
	/**
	 * @param array<string, scalar> $data
	 * @return array<string, mixed>
	 */
	public function um_add_user( array $data ): array {
		// Legacy (v6): /tool user-manager expects '=login='
		$legacy = array( '/tool/user-manager/user/add' );
		if ( isset( $data['login'] ) ) {
			$legacy[] = '=login=' . $data['login'];
		} elseif ( isset( $data['username'] ) ) {
			$legacy[] = '=login=' . $data['username'];
		}
		foreach ( array( 'password', 'customer', 'group', 'comment' ) as $k ) {
			if ( ! empty( $data[ $k ] ) ) {
				$legacy[] = '=' . $k . '=' . $data[ $k ];
			}
		}

		// v7: /user-manager expects '=name='
		$v7 = array( '/user-manager/user/add' );
		if ( isset( $data['username'] ) ) {
			$v7[] = '=name=' . $data['username'];
		} elseif ( isset( $data['login'] ) ) {
			$v7[] = '=name=' . $data['login'];
		}
		foreach ( array( 'password', 'group', 'comment' ) as $k ) {
			if ( ! empty( $data[ $k ] ) ) {
				/** @var scalar $value */
				$value = $data[ $k ];
				$v7[]  = '=' . $k . '=' . $value;
			}
		}

		$r = $this->um_request_dual( $legacy, $v7 );
		return ! empty( $r['success'] ) ? array( 'success' => true ) : $r;
	}
	/**
	 * @param array<string, scalar> $data
	 * @return array<string, mixed>
	 */
	public function um_set_user( string $id_or_login, array $data ): array {
		// RouterOS expects internal id in '=numbers='; usernames with spaces will fail if used directly.
		$numbers  = $id_or_login;
		$resolved = null;
		if ( strpos( $id_or_login, '*' ) !== 0 ) {
			// Resolve .id by matching username/login/name case-insensitively
			$list = $this->um_list_users();
			foreach ( $list as $row ) {
				$name = $row['username'] ?? ( $row['login'] ?? ( $row['name'] ?? '' ) );
				if ( '' !== $name && strcasecmp( $name, $id_or_login ) === 0 ) {
					$candidate = $row['.id'] ?? $row['id'] ?? $id_or_login;
					if ( '' !== $candidate ) {
						$resolved = $candidate;
						$numbers  = $candidate;
					}
					break;
				}
			}
			if ( null !== $resolved && $resolved !== $id_or_login && class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log(
					'info',
					'router_id_resolution',
					'Resolved router user identifier to internal id',
					array(
						'input'    => $id_or_login,
						'resolved' => $resolved,
					)
				);
			}
		}
		$words = array( '/tool/user-manager/user/set', '=numbers=' . $numbers );
		foreach ( array( 'password', 'group', 'comment' ) as $k ) {
			if ( isset( $data[ $k ] ) && $data[ $k ] !== '' ) {
				$words[] = '=' . $k . '=' . $data[ $k ];
			}
		}
		$r = $this->um_request_dual( $words, array_merge( array( '/user-manager/user/set' ), array_slice( $words, 1 ) ) );
		return ! empty( $r['success'] ) ? array( 'success' => true ) : $r;
	}
	/**
	 * @return array<string, mixed>
	 */
	public function um_remove_user( string $id_or_login ): array {
		$r = $this->um_request_dual( array( '/tool/user-manager/user/remove', '=numbers=' . $id_or_login ), array( '/user-manager/user/remove', '=numbers=' . $id_or_login ) );
		return ! empty( $r['success'] ) ? array( 'success' => true ) : $r;
	}
	/**
	 * @return array<int, array<string, string>>
	 * Note: In User Manager, "User Groups" are actually RADIUS configurations.
	 * The user "group" field references RADIUS server names (e.g., p-DE, p-US).
	 */
	public function um_list_groups(): array {
		// RouterOS 7.x User Manager doesn't expose groups via API
		// Return profiles as groups since they serve a similar organizational purpose
		$profiles = $this->um_list_profiles();
		$groups = array();
		foreach ( $profiles as $profile ) {
			if ( isset( $profile['name'] ) ) {
				$groups[] = array(
					'name' => $profile['name'],
					'.id' => $profile['.id'] ?? '*' . count( $groups ),
				);
			}
		}
		return $groups;
	}
	/**
	 * @param array<string, scalar> $data
	 * @return array<string, mixed>
	 */
	public function um_add_group( array $data ): array {
		$legacy = array( '/tool/user-manager/group/add', '=name=' . ( $data['name'] ?? '' ) );
		$v7     = array( '/user-manager/group/add', '=name=' . ( $data['name'] ?? '' ) );
		$r      = $this->um_request_dual( $legacy, $v7 );
		return ! empty( $r['success'] ) ? array( 'success' => true ) : $r;
	}
	/**
	 * @return array<string, mixed>
	 */
	public function um_remove_group( string $id_or_name ): array {
		$r = $this->um_request_dual( array( '/tool/user-manager/group/remove', '=numbers=' . $id_or_name ), array( '/user-manager/group/remove', '=numbers=' . $id_or_name ) );
		return ! empty( $r['success'] ) ? array( 'success' => true ) : $r;
	}
	/**
	 * @return array<int, array<string, string>>
	 */
	public function um_list_profiles(): array {
		// Try v7 path first (RouterOS 7.x), then fall back to v6
		$rows = $this->print_resource( '/user-manager/profile/print' );
		if ( empty( $rows ) ) {
			$rows = $this->print_resource( '/tool/user-manager/profile/print' );
		}
		return $rows;
	}
	/**
	 * @return array<string, mixed>
	 */
	public function um_user_profile_add( string $user, string $profile ): array {
		// Try with key 'user' first, then fallback to 'users' if the device expects plural argument
		$r = $this->um_request_dual(
			array( '/tool/user-manager/user-profile/add', '=user=' . $user, '=profile=' . $profile ),
			array( '/user-manager/user-profile/add', '=user=' . $user, '=profile=' . $profile )
		);
		if ( ! empty( $r['success'] ) ) {
			return array( 'success' => true );
		}
		$msg = strtolower( $r['message'] ?? '' );
		if ( $msg && ( strpos( $msg, 'input' ) !== false || strpos( $msg, 'parameter' ) !== false || strpos( $msg, 'argument' ) !== false ) ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log(
					'notice',
					'router_param_retry',
					'Retrying user-profile add with alternate parameter key',
					array(
						'user'          => $user,
						'profile'       => $profile,
						'original_key'  => 'user',
						'alternate_key' => 'users',
						'error_message' => $r['message'] ?? null,
					)
				);
			}
			$r2 = $this->um_request_dual(
				array( '/tool/user-manager/user-profile/add', '=users=' . $user, '=profile=' . $profile ),
				array( '/user-manager/user-profile/add', '=users=' . $user, '=profile=' . $profile )
			);
			return ! empty( $r2['success'] ) ? array( 'success' => true ) : $r2;
		}
		return $r;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	public function um_list_user_profiles(): array {
		// Prefer v7 path first
		$rows = $this->print_resource( '/user-manager/user-profile/print' );
		if ( empty( $rows ) ) {
			$rows = $this->print_resource( '/tool/user-manager/user-profile/print' );
		}
		return $rows;
	}

	/**
	 * @return array<int, string>
	 */
	protected function um_find_user_profile_ids( string $user, string $profile ): array {
		// Try v7 with filters
		$try = function ( array $words ) use ( $user, $profile ): array {
			/** @var array<int, string> $words */
			$res = $this->request( $words );
			if ( ! $this->is_success( $res ) ) {
				return array();
			}
			if ( ! array_key_exists( 'replies', $res ) ) {
				return array();
			}
			$replies = $res['replies'];
			if ( ! is_array( $replies ) ) {
				return array();
			}
			/** @var array<int, array<int, string>> $replies */
			$rows = $this->parse_rows( $replies );
			$out  = array();
			foreach ( $rows as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				/** @var array<string, string> $r */
				$u = strtolower( trim( $r['user'] ?? $r['users'] ?? $r['name'] ?? '' ) );
				$p = $r['profile'] ?? '';
				if ( '' !== $u && '' !== $p && $u === strtolower( trim( $user ) ) && strcasecmp( $p, $profile ) === 0 ) {
					$out[] = $r['.id'] ?? $r['id'] ?? '';
				}
			}
			return $out;
		};
		$ids = $try( array( '/user-manager/user-profile/print', '?user=' . $user, '?profile=' . $profile ) );
		if ( empty( $ids ) ) {
			$ids = $try( array( '/user-manager/user-profile/print', '?users=' . $user, '?profile=' . $profile ) );
		}
		if ( empty( $ids ) ) {
			$ids = $try( array( '/tool/user-manager/user-profile/print', '?user=' . $user, '?profile=' . $profile ) );
		}
		if ( empty( $ids ) ) {
			$ids = $try( array( '/tool/user-manager/user-profile/print', '?users=' . $user, '?profile=' . $profile ) );
		}
		if ( ! empty( $ids ) ) {
			return $ids;
		}
		// Fallback: scan all
		$rows = $this->um_list_user_profiles();
		$ids  = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$u = strtolower( trim( $r['user'] ?? $r['users'] ?? $r['name'] ?? '' ) );
			$p = $r['profile'] ?? '';
			if ( '' !== $u && '' !== $p && $u === strtolower( trim( $user ) ) && strcasecmp( $p, $profile ) === 0 ) {
				$ids[] = $r['.id'] ?? $r['id'] ?? '';
			}
		}
		return $ids;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function um_user_profile_remove( string $user, string $profile ): array {
		// Find the internal id for the user-profile pair
		$ids = $this->um_find_user_profile_ids( $user, $profile );
		if ( empty( $ids ) ) {
			return array(
				'success' => false,
				'message' => 'Assignment not found',
			);
		}
		$lastErr = null;
		$removed = 0;
		foreach ( $ids as $target ) {
			if ( ! $target ) {
				continue;
			}
			$r = $this->um_request_dual(
				array( '/tool/user-manager/user-profile/remove', '=numbers=' . $target ),
				array( '/user-manager/user-profile/remove', '=numbers=' . $target )
			);
			if ( $this->is_success( $r ) ) {
				++$removed;
			} else {
				$lastErr = $r;
			}
		}
		if ( $removed > 0 ) {
			return array( 'success' => true );
		}
		return $lastErr ?: array(
			'success' => false,
			'message' => 'Remove failed',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function um_user_profile_remove_all_for_user( string $user ): array {
		$rows = $this->um_list_user_profiles();
		$ids  = array();
		foreach ( $rows as $r ) {
			$u = strtolower( trim( $r['user'] ?? $r['users'] ?? $r['name'] ?? '' ) );
			if ( $u === strtolower( trim( $user ) ) ) {
				$ids[] = $r['.id'] ?? $r['id'] ?? '';
			}
		}
		$removed = 0;
		$lastErr = null;
		foreach ( $ids as $id ) {
			if ( ! $id ) {
				continue;
			}
			$r = $this->um_request_dual(
				array( '/tool/user-manager/user-profile/remove', '=numbers=' . $id ),
				array( '/user-manager/user-profile/remove', '=numbers=' . $id )
			);
			if ( ! empty( $r['success'] ) ) {
				++$removed;
			} else {
				$lastErr = $r;
			}
		}
		return $removed > 0 ? array(
			'success' => true,
			'removed' => $removed,
		) : ( $lastErr ?: array(
			'success' => true,
			'removed' => 0,
		) );
	}
}
