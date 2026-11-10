<?php

namespace MUMB\Clients;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface RouterClientInterface {

	/**
	 * @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string, error_code?:string}
	 */
	public function getUsers(): array;

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function createUser( array $data ): array;

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function updateUser( string $id_or_login, array $data ): array;

	/**
	 * @return array<string, mixed>
	 */
	public function deleteUser( string $id_or_login ): array;

	/**
	 * @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string, error_code?:string}
	 */
	public function getProfiles(): array;

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function createProfile( array $data ): array;

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function updateProfile( string $id_or_name, array $data ): array;

	/**
	 * @return array<string, mixed>
	 */
	public function deleteProfile( string $id_or_name ): array;

	/**
	 * @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string, error_code?:string}
	 */
	public function getGroups(): array;

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function createGroup( array $data ): array;

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function updateGroup( string $id_or_name, array $data ): array;

	/**
	 * @return array<string, mixed>
	 */
	public function deleteGroup( string $id_or_name ): array;

	/**
	 * @return array<string, mixed>
	 */
	public function assignProfile( string $username, string $profile ): array;

	/**
	 * @return array<string, mixed>
	 */
	public function removeProfile( string $username, string $profile ): array;

	/**
	 * @return array<string, mixed>
	 */
	public function removeAllProfiles( string $username ): array;

	/**
	 * @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string}
	 */
	public function getSessions(): array;

	/**
	 * @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string, error_code?:string}
	 */
	public function getUserProfiles(): array;

	/**
	 * @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string}
	 */
	public function getUserUsageCounters(): array;

	/**
	 * @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string, error_code?:string}
	 */
	public function getRouters(): array;

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function createRouter( array $data ): array;

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function updateRouter( string $id, array $data ): array;

	/**
	 * @return array<string, mixed>
	 */
	public function deleteRouter( string $id ): array;
}
