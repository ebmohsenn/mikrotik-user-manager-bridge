<?php

namespace MUMB\Clients;

use MUMB_Logger;
use MUMB_RouterOS_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BinaryRouterClient implements RouterClientInterface {

	protected MUMB_RouterOS_API $api;

	public function __construct( MUMB_RouterOS_API $api ) {
		$this->api = $api;
	}

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string, error_code?: string}
	 */
	public function getUsers(): array {
		try {
			$rows = $this->api->um_list_users();
			return array(
				'status' => 'ok',
				'data'   => $rows,
			);
		} catch ( \Throwable $e ) {
			return $this->error( 'Failed to fetch users via binary API', $e );
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{status: string, data?: mixed, error_message?: string, error_code?: string}
	 */
	public function createUser( array $data ): array {
		$res = $this->api->um_add_user( $this->filter_scalar_data( $data ) );
		return $this->normalize_status( $res, 'Failed to create user (binary)' );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{status: string, data?: mixed, error_message?: string, error_code?: string}
	 */
	public function updateUser( string $id_or_login, array $data ): array {
		$res = $this->api->um_set_user( $id_or_login, $this->filter_scalar_data( $data ) );
		return $this->normalize_status( $res, 'Failed to update user (binary)' );
	}

	/**
	 * @return array{status: string, data?: mixed, error_message?: string, error_code?: string}
	 */
	public function deleteUser( string $id_or_login ): array {
		$res = $this->api->um_remove_user( $id_or_login );
		return $this->normalize_status( $res, 'Failed to delete user (binary)' );
	}

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string, error_code?: string}
	 */
	public function getProfiles(): array {
		try {
			$rows = $this->api->um_list_profiles();
			return array(
				'status' => 'ok',
				'data'   => $rows,
			);
		} catch ( \Throwable $e ) {
			return $this->error( 'Failed to fetch profiles via binary API', $e );
		}
	}

	public function createProfile( array $data ): array {
		return array(
			'status'        => 'error',
			'error_code'    => 'not_supported',
			'error_message' => 'Profile creation requires UM REST',
		);
	}

	public function updateProfile( string $id_or_name, array $data ): array {
		return array(
			'status'        => 'error',
			'error_code'    => 'not_supported',
			'error_message' => 'Profile update requires UM REST',
		);
	}

	public function deleteProfile( string $id_or_name ): array {
		return array(
			'status'        => 'error',
			'error_code'    => 'not_supported',
			'error_message' => 'Profile delete requires UM REST',
		);
	}

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string, error_code?: string}
	 */
	public function getGroups(): array {
		try {
			$rows = $this->api->um_list_groups();
			return array(
				'status' => 'ok',
				'data'   => $rows,
			);
		} catch ( \Throwable $e ) {
			return $this->error( 'Failed to fetch groups via binary API', $e );
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{status: string, data?: mixed, error_message?: string, error_code?: string}
	 */
	public function createGroup( array $data ): array {
		$res = $this->api->um_add_group( $this->filter_scalar_data( $data ) );
		return $this->normalize_status( $res, 'Failed to create group (binary)' );
	}

	/**
	 * @return array{status: string, data?: mixed, error_message?: string, error_code?: string}
	 */
	public function updateGroup( string $id_or_name, array $data ): array {
		return array(
			'status'        => 'error',
			'error_code'    => 'not_supported',
			'error_message' => 'Group update requires UM REST',
		);
	}

	/**
	 * @return array{status: string, data?: mixed, error_message?: string, error_code?: string}
	 */
	public function deleteGroup( string $id_or_name ): array {
		$res = $this->api->um_remove_group( $id_or_name );
		return $this->normalize_status( $res, 'Failed to delete group (binary)' );
	}

	/**
	 * @return array{status: string, data?: mixed, error_message?: string, error_code?: string}
	 */
	public function assignProfile( string $username, string $profile ): array {
		$res = $this->api->um_user_profile_add( $username, $profile );
		return $this->normalize_status( $res, 'Failed to assign profile (binary)' );
	}

	/**
	 * @return array{status: string, data?: mixed, error_message?: string, error_code?: string}
	 */
	public function removeProfile( string $username, string $profile ): array {
		$res = $this->api->um_user_profile_remove( $username, $profile );
		return $this->normalize_status( $res, 'Failed to remove profile (binary)' );
	}

	/**
	 * @return array{status: string, data?: mixed, error_message?: string, error_code?: string}
	 */
	public function removeAllProfiles( string $username ): array {
		$res = $this->api->um_user_profile_remove_all_for_user( $username );
		if ( ! empty( $res['success'] ) ) {
			$removed = isset( $res['removed'] ) && is_numeric( $res['removed'] ) ? (int) $res['removed'] : 0;
			return array(
				'status' => 'ok',
				'data'   => $removed,
			);
		}
		return $this->normalize_status( $res, 'Failed to remove profiles (binary)' );
	}

	/**
	 * @return (string|string[][])[]
	 *
	 * @psalm-return array{status: string, error_message?: string, error_code?: string, data?: array<int, array<string, string>>}
	 */
	public function getSessions(): array {
		try {
			$rows = $this->api->print_resource( '/ppp/active/print' );
			return array(
				'status' => 'ok',
				'data'   => $rows,
			);
		} catch ( \Throwable $e ) {
			return $this->error( 'Failed to fetch active sessions (binary)', $e );
		}
	}

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string, error_code?: string}
	 */
	public function getUserProfiles(): array {
		try {
			$rows = $this->api->um_list_user_profiles();
			return array(
				'status' => 'ok',
				'data'   => $rows,
			);
		} catch ( \Throwable $e ) {
			return $this->error( 'Failed to fetch user profiles (binary)', $e );
		}
	}

	/**
	 * @return (string|string[][])[]
	 *
	 * @psalm-return array{status: string, data?: list<array{actual_profile?: non-empty-string, bytes_in?: non-empty-string, bytes_out?: non-empty-string, comment?: non-empty-string, disabled?: string, download_limit?: non-empty-string, download_used?: non-empty-string, last_login?: non-empty-string, last_seen?: non-empty-string, profile?: non-empty-string, upload_limit?: non-empty-string, upload_used?: non-empty-string, uptime?: non-empty-string, username: non-empty-string}>, error_message?: string, error_code?: string}
	 */
	public function getUserUsageCounters(): array {
		try {
			$rows = $this->api->print_resource( '/tool/user-manager/user/print' );
			if ( empty( $rows ) ) {
				$rows = $this->api->print_resource( '/user-manager/user/print' );
			}
		} catch ( \Throwable $e ) {
			return $this->error( 'Failed to fetch usage counters (binary)', $e );
		}

		$data = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$username = '';
			foreach ( array( 'username', 'name', 'login' ) as $key ) {
				if ( isset( $row[ $key ] ) && $row[ $key ] !== '' ) {
					$username = $row[ $key ];
					break;
				}
			}
			if ( '' === $username ) {
				continue;
			}
			$entry = array( 'username' => $username );
			foreach (
				array(
					'bytes-in'       => 'bytes_in',
					'bytes-out'      => 'bytes_out',
					'download-used'  => 'download_used',
					'upload-used'    => 'upload_used',
					'download-limit' => 'download_limit',
					'upload-limit'   => 'upload_limit',
					'uptime'         => 'uptime',
					'last-seen'      => 'last_seen',
					'last-login'     => 'last_login',
					'actual-profile' => 'actual_profile',
					'profile'        => 'profile',
					'comment'        => 'comment',
				) as $source => $target
			) {
				if ( isset( $row[ $source ] ) && $row[ $source ] !== '' ) {
					$entry[ $target ] = $row[ $source ];
				}
			}
			if ( isset( $row['disabled'] ) ) {
				$entry['disabled'] = $row['disabled'];
			}
			$data[] = $entry;
		}

		/** @var array<int, array<string, mixed>> $data */
		return array(
			'status' => 'ok',
			'data'   => $data,
		);
	}

	public function getRouters(): array {
		return array(
			'status'        => 'error',
			'error_code'    => 'not_supported',
			'error_message' => 'Router inventory requires UM REST',
		);
	}

	public function createRouter( array $data ): array {
		return array(
			'status'        => 'error',
			'error_code'    => 'not_supported',
			'error_message' => 'Router create requires UM REST',
		);
	}

	public function updateRouter( string $id, array $data ): array {
		return array(
			'status'        => 'error',
			'error_code'    => 'not_supported',
			'error_message' => 'Router update requires UM REST',
		);
	}

	public function deleteRouter( string $id ): array {
		return array(
			'status'        => 'error',
			'error_code'    => 'not_supported',
			'error_message' => 'Router delete requires UM REST',
		);
	}

	/**
	 * @param array<string, mixed> $res
	 * @return array{status: string, data?: mixed, error_message?: string, error_code?: string}
	 */
	private function normalize_status( array $res, string $message ): array {
		if ( ! empty( $res['success'] ) ) {
			return array(
				'status' => 'ok',
				'data'   => true,
			);
		}
		$error_message = isset( $res['message'] ) && is_scalar( $res['message'] ) ? (string) $res['message'] : $message;
		if ( class_exists( 'MUMB_Logger' ) ) {
			MUMB_Logger::log( 'warning', 'router_binary', $message, array( 'error' => $error_message ) );
		}
		return array(
			'status'        => 'error',
			'error_message' => $error_message,
			'error_code'    => 'binary_api',
		);
	}

	/**
	 * Filter array to only include scalar values (bool|float|int|string)
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, bool|float|int|string>
	 */
	private function filter_scalar_data( array $data ): array {
		$filtered = array();
		/** @var mixed $value */
		foreach ( $data as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$filtered[ $key ] = $value;
			}
		}
		return $filtered;
	}

	/**
	 * @return array{status: string, error_message: string, error_code: string}
	 */
	private function error( string $message, \Throwable $e ): array {
		if ( class_exists( 'MUMB_Logger' ) ) {
			MUMB_Logger::log( 'error', 'router_binary', $message, array( 'exception' => $e->getMessage() ) );
		}
		return array(
			'status'        => 'error',
			'error_message' => $e->getMessage(),
			'error_code'    => 'binary_exception',
		);
	}
}
