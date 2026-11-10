<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface RouterClientInterface {

	/** @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string, error_code?:string} */
	public function get_users(): array;

	/** @param array<string, mixed> $data @return array<string, mixed> */
	public function add_user( array $data ): array;

	/** @param array<string, mixed> $data @return array<string, mixed> */
	public function update_user( string $id_or_login, array $data ): array;

	/** @return array<string, mixed> */
	public function delete_user( string $id_or_login ): array;

	/** @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string, error_code?:string} */
	public function get_groups(): array;

	/** @param array<string, mixed> $data @return array<string, mixed> */
	public function add_group( array $data ): array;

	/** @param array<string, mixed> $data @return array<string, mixed> */
	public function update_group( string $id_or_name, array $data ): array;

	/** @return array<string, mixed> */
	public function delete_group( string $id_or_name ): array;

	/** @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string, error_code?:string} */
	public function get_profiles(): array;

	/** @param array<string, mixed> $data @return array<string, mixed> */
	public function create_profile( array $data ): array;

	/** @param array<string, mixed> $data @return array<string, mixed> */
	public function update_profile( string $id_or_name, array $data ): array;

	/** @return array<string, mixed> */
	public function delete_profile( string $id_or_name ): array;

	/** @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string, error_code?:string} */
	public function get_user_profiles(): array;

	/** @return array<string, mixed> */
	public function assign_profile( string $username, string $profile ): array;

	/** @return array<string, mixed> */
	public function remove_user_all_profiles( string $username ): array;

	/** @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string} */
	public function get_active_sessions(): array;

	/** @return array{status:string, data?:array<int, array<string, mixed>>, error_message?:string} */
	public function get_user_usage_counters(): array;

	public function supports_dual_namespace(): bool;

	public function supports_profile_updates(): bool;

	public function uses_rest_transport(): bool;
}
