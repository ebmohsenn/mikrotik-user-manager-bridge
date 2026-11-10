<?php

namespace MUMB\Clients;

use MUMB_Logger;
use MUMB_UM_REST;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestRouterClient implements RouterClientInterface {

	protected MUMB_UM_REST $rest;

	public function __construct( MUMB_UM_REST $rest ) {
		$this->rest = $rest;
	}

	public function getUsers(): array {
		$res = $this->rest->list_users();
		return $this->list_response( $res, 'user' );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function createUser( array $data ): array {
		$payload = $data;
		if ( isset( $payload['username'] ) && ! isset( $payload['name'] ) ) {
			/** @var mixed $username */
			$username        = $payload['username'];
			$payload['name'] = $username;
		}
		$res = $this->rest->create_user( $this->filter_scalar_data( $payload ) );
		return $this->mutation_response( $res, 'Failed to create user (REST)' );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function updateUser( string $id_or_login, array $data ): array {
		$payload = $data;
		if ( isset( $payload['shared-users'] ) ) {
			$payload['shared-users'] = is_numeric( $payload['shared-users'] ) ? (int) $payload['shared-users'] : 0;
		}
		if ( array_key_exists( 'disabled', $payload ) ) {
			$payload['disabled'] = $payload['disabled'] ? 'true' : 'false';
		}

		$filtered_payload = $this->filter_scalar_data( $payload );
		$res              = $this->rest->update_user( $id_or_login, $filtered_payload );

		// If we get a "not found" error, try to resolve the username to an ID
		if ( is_wp_error( $res ) && $res->get_error_code() === 'not_found' ) {
			$users_res = $this->rest->list_users();
			if ( ! is_wp_error( $users_res ) ) {
				/** @var array<array-key, mixed> $users_res */
				/** @var mixed $user */
				foreach ( $users_res as $user ) {
					if ( is_array( $user ) && isset( $user['name'] ) && $user['name'] === $id_or_login && isset( $user['id'] ) && is_string( $user['id'] ) ) {
						// Retry with the resolved ID
						$res = $this->rest->update_user( $user['id'], $filtered_payload );
						break;
					}
				}
			}
		}

		return $this->mutation_response( $res, 'Failed to update user (REST)' );
	}

	public function deleteUser( string $id_or_login ): array {
		$res = $this->rest->delete_user( $id_or_login );
		return $this->mutation_response( $res, 'Failed to delete user (REST)' );
	}

	public function getProfiles(): array {
		$res = $this->rest->list_profiles();
		return $this->list_response( $res, 'profile' );
	}

	public function createProfile( array $data ): array {
		$res = $this->rest->create_profile( $this->filter_scalar_data( $data ) );
		return $this->mutation_response( $res, 'Failed to create profile (REST)' );
	}

	public function updateProfile( string $id_or_name, array $data ): array {
		$res = $this->rest->update_profile( $id_or_name, $this->filter_scalar_data( $data ) );
		return $this->mutation_response( $res, 'Failed to update profile (REST)' );
	}

	public function deleteProfile( string $id_or_name ): array {
		$res = $this->rest->delete_profile( $id_or_name );
		return $this->mutation_response( $res, 'Failed to delete profile (REST)' );
	}

	public function getGroups(): array {
		$res = $this->rest->list_groups();
		return $this->list_response( $res, 'group' );
	}

	public function createGroup( array $data ): array {
		$res = $this->rest->create_group( $this->filter_scalar_data( $data ) );
		return $this->mutation_response( $res, 'Failed to create group (REST)' );
	}

	public function updateGroup( string $id_or_name, array $data ): array {
		$res = $this->rest->update_group( $id_or_name, $this->filter_scalar_data( $data ) );
		return $this->mutation_response( $res, 'Failed to update group (REST)' );
	}

	public function deleteGroup( string $id_or_name ): array {
		$res = $this->rest->delete_group( $id_or_name );
		return $this->mutation_response( $res, 'Failed to delete group (REST)' );
	}

	public function assignProfile( string $username, string $profile ): array {
		$res = $this->rest->create_user_profile(
			array(
				'user'    => $username,
				'profile' => $profile,
			)
		);
		return $this->mutation_response( $res, 'Failed to assign profile (REST)' );
	}

	public function removeProfile( string $username, string $profile ): array {
		$list = $this->rest->list_user_profiles();
		if ( is_wp_error( $list ) ) {
			return $this->error( 'Failed to list user profiles (REST)', $list );
		}
		$target = $this->find_profile_assignment_id( $list, $username, $profile );
		if ( ! $target ) {
			return array(
				'status'        => 'error',
				'error_message' => 'Assignment not found',
				'error_code'    => 'not_found',
			);
		}
		$res = $this->rest->delete_user_profile( $target );
		return $this->mutation_response( $res, 'Failed to remove profile (REST)' );
	}

	public function removeAllProfiles( string $username ): array {
		$list = $this->rest->list_user_profiles();
		if ( is_wp_error( $list ) ) {
			return $this->error( 'Failed to list user profiles (REST)', $list );
		}
		$count = 0;
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$userField = (string) ( $row['user'] ?? $row['users'] ?? $row['name'] ?? '' );
			if ( '' === $userField || strcasecmp( $userField, $username ) !== 0 ) {
				continue;
			}
			$id = (string) ( $row['id'] ?? $row['.id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$res = $this->rest->delete_user_profile( $id );
			if ( ! is_wp_error( $res ) ) {
				++$count;
			}
		}
		return array(
			'status' => 'ok',
			'data'   => $count,
		);
	}

	public function getSessions(): array {
		// RouterOS REST does not yet expose PPP sessions; return an empty success payload so higher layers can fall back.
		return array(
			'status' => 'ok',
			'data'   => array(),
		);
	}

	public function getUserProfiles(): array {
		$res = $this->rest->list_user_profiles();
		return $this->list_response( $res, 'user-profile' );
	}

	public function getUserUsageCounters(): array {
		// REST does not currently expose aggregated usage counters; return empty success payload.
		return array(
			'status' => 'ok',
			'data'   => array(),
		);
	}

	public function getRouters(): array {
		$res = $this->rest->list_routers();
		return $this->list_response( $res, 'router' );
	}

	public function createRouter( array $data ): array {
		$res = $this->rest->create_router( $this->filter_scalar_data( $data ) );
		return $this->mutation_response( $res, 'Failed to create router (REST)' );
	}

	public function updateRouter( string $id, array $data ): array {
		$res = $this->rest->update_router( $id, $this->filter_scalar_data( $data ) );
		return $this->mutation_response( $res, 'Failed to update router (REST)' );
	}

	public function deleteRouter( string $id ): array {
		$res = $this->rest->delete_router( $id );
		return $this->mutation_response( $res, 'Failed to delete router (REST)' );
	}

	/**
	 * @param array<array-key, mixed>|WP_Error $res
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string, error_code?: string}
	 */
	private function list_response( $res, string $type ): array {
		if ( is_wp_error( $res ) ) {
			/** @var array{status: string, error_message: string, error_code: string} */
			$error_response = $this->error( 'Failed to fetch ' . $type . 's via REST', $res );
			return $error_response;
		}
		$rows = array();
		if ( is_array( $res ) ) {
			foreach ( $res as $row ) {
				/** @var mixed $row */
				if ( is_array( $row ) ) {
					/** @var array<string, mixed> $row */
					$rows[] = $row;
				}
			}
		}
		return array(
			'status' => 'ok',
			'data'   => $rows,
		);
	}

	/**
	 * @param array<array-key, mixed>|WP_Error $res
	 * @return array<string, mixed>
	 */
	private function mutation_response( $res, string $message ): array {
		if ( is_wp_error( $res ) ) {
			return $this->error( $message, $res );
		}
		return array(
			'status' => 'ok',
			'data'   => true,
		);
	}

	/**
	 * @param WP_Error $error
	 * @return array<string, mixed>
	 */
	private function error( string $message, WP_Error $error ): array {
		if ( class_exists( 'MUMB_Logger' ) ) {
			MUMB_Logger::log( 'warning', 'router_rest', $message, array( 'error' => $error->get_error_message() ) );
		}
		return array(
			'status'        => 'error',
			'error_message' => $error->get_error_message(),
			'error_code'    => $error->get_error_code() ?: 'rest_error',
		);
	}

	/**
	 * @param array<array-key, mixed> $list
	 */
	private function find_profile_assignment_id( array $list, string $username, string $profile ): ?string {
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$userField    = (string) ( $row['user'] ?? $row['users'] ?? $row['name'] ?? '' );
			$profileField = (string) ( $row['profile'] ?? '' );
			if ( '' !== $userField && '' !== $profileField && strcasecmp( $userField, $username ) === 0 && strcasecmp( $profileField, $profile ) === 0 ) {
				$id = (string) ( $row['id'] ?? $row['.id'] ?? '' );
				if ( '' !== $id ) {
					return $id;
				}
			}
		}
		return null;
	}

	/**
	 * Filter array to only include scalar values (bool|float|int|string)
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, bool|float|int|string>
	 */
	private function filter_scalar_data( array $data ): array {
		$filtered = array();
		foreach ( $data as $key => $value ) {
			/** @var mixed $value */
			if ( is_scalar( $value ) ) {
				$filtered[ $key ] = $value;
			}
		}
		return $filtered;
	}
}
