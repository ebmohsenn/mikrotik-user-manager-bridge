<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MUMB\Clients\BinaryRouterClient;
use MUMB\Clients\RestRouterClient;
use MUMB\Clients\RouterClientInterface;
use MUMB\Services\CapabilityService;
use MUMB\Services\SettingsService;

/**
 * Router client adapter/factory with automatic transport selection.
 */
class MikroTik_API {

	// Test support: static properties for tracking calls and simulating failures
	/** @var array<int, array<string, mixed>> */
	public static array $calls = array();
	/** @var array<string, int> */
	public static array $failures = array();

	protected function maybeFail( string $key ): ?array {
		if ( defined( 'MWP_TEST_SUITE' ) || defined( 'WP_TESTS_DOMAIN' ) ) {
			if ( isset( self::$failures[ $key ] ) && self::$failures[ $key ] > 0 ) {
				--self::$failures[ $key ];
				return array(
					'status'        => 'error',
					'error_message' => 'Simulated failure',
				);
			}
		}
		return null;
	}

	/**
	 * Get the failure key for a given API method (for test mode).
	 */
	protected function getMethodFailureKey( string $method ): ?string {
		$methodMap = array(
			'getUsers'          => 'get_users',
			'createUser'        => 'add_user',
			'updateUser'        => 'update_user',
			'deleteUser'        => 'delete_user',
			'getProfiles'       => 'get_profiles',
			'createProfile'     => 'create_profile',
			'updateProfile'     => 'update_profile',
			'deleteProfile'     => 'delete_profile',
			'getGroups'         => 'get_groups',
			'createGroup'       => 'create_group',
			'updateGroup'       => 'update_group',
			'deleteGroup'       => 'delete_group',
			'assignProfile'     => 'assign_profile',
			'removeProfile'     => 'remove_user_profile',
			'removeAllProfiles' => 'remove_user_all_profiles',
		);
		return $methodMap[ $method ] ?? null;
	}

	/** @var array<string, mixed> */
	private array $opts;
	private CapabilityService $capabilityService;
	/** @var array<string, mixed> */
	private array $capabilities                    = array();
	private ?RouterClientInterface $primaryClient  = null;
	private ?RouterClientInterface $fallbackClient = null;
	private ?BinaryRouterClient $binaryClient      = null;
	private ?RestRouterClient $restClient          = null;
	private ?MUMB_RouterOS_API $routeros           = null;
	private ?MUMB_UM_REST $umRest                  = null;
	private bool $usingInjectedClients             = false;

	/**
	 * @param array<string, mixed> $opts
	 */
	public function __construct(
		array $opts,
		?CapabilityService $capabilityService = null,
		?RouterClientInterface $primaryClient = null,
		?RouterClientInterface $fallbackClient = null,
		?BinaryRouterClient $binaryClient = null,
		?RestRouterClient $restClient = null
	) {
		$this->opts              = $this->normalise_opts( $opts );
		$this->capabilityService = $capabilityService ?? new CapabilityService();
		$this->capabilities      = $this->capabilityService->get_capabilities();

		if ( $primaryClient ) {
			$this->primaryClient        = $primaryClient;
			$this->fallbackClient       = $fallbackClient;
			$this->binaryClient         = $binaryClient;
			$this->restClient           = $restClient;
			$this->usingInjectedClients = true;
			return;
		}

		$this->initialise_clients();
	}

	/**
	 * Expose the selected router client for new code paths.
	 */
	public function get_client(): RouterClientInterface {
		if ( ! $this->primaryClient ) {
			throw new RuntimeException( 'Router client not initialised' );
		}
		return $this->primaryClient;
	}

	public function has_fallback_client(): bool {
		return $this->fallbackClient instanceof RouterClientInterface;
	}

	// ---------------------------------------------------------------------
	// User operations

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string}
	 */
	public function get_users(): array {
		/** @var array{status: string, data?: array<int, array<string, mixed>>, error_message?: string} */
		return $this->call( 'getUsers', array() );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{status: string, data?: array<string, mixed>, error_message?: string}
	 */
	public function add_user( array $data ): array {
		$this->record( 'add_user', $data );
		/** @var array{status: string, data?: array<string, mixed>, error_message?: string} */
		return $this->call( 'createUser', array( $data ) );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{status: string, data?: array<string, mixed>, error_message?: string}
	 */
	public function update_user( string $id_or_login, array $data ): array {
		$this->record(
			'update_user',
			array(
				'id'   => $id_or_login,
				'data' => $data,
			)
		);
		/** @var array{status: string, data?: array<string, mixed>, error_message?: string} */
		return $this->call( 'updateUser', array( $id_or_login, $data ) );
	}

	/**
	 * @return array{status: string, error_message?: string}
	 */
	public function delete_user( string $id_or_login ): array {
		/** @var array{status: string, error_message?: string} */
		return $this->call( 'deleteUser', array( $id_or_login ) );
	}

	// ---------------------------------------------------------------------
	// Group operations

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string}
	 */
	public function get_groups(): array {
		/** @var array{status: string, data?: array<int, array<string, mixed>>, error_message?: string} */
		return $this->call( 'getGroups', array() );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{status: string, data?: array<string, mixed>, error_message?: string}
	 */
	public function add_group( array $data ): array {
		/** @var array{status: string, data?: array<string, mixed>, error_message?: string} */
		return $this->call( 'createGroup', array( $data ), $this->restClient !== null );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{status: string, data?: array<string, mixed>, error_message?: string}
	 */
	public function update_group( string $id_or_name, array $data ): array {
		/** @var array{status: string, data?: array<string, mixed>, error_message?: string} */
		return $this->call( 'updateGroup', array( $id_or_name, $data ), $this->restClient !== null );
	}

	/**
	 * @return array{status: string, error_message?: string}
	 */
	public function delete_group( string $id_or_name ): array {
		/** @var array{status: string, error_message?: string} */
		return $this->call( 'deleteGroup', array( $id_or_name ), $this->restClient !== null );
	}

	// ---------------------------------------------------------------------
	// Profile operations

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string}
	 */
	public function get_profiles(): array {
		/** @var array{status: string, data?: array<int, array<string, mixed>>, error_message?: string} */
		return $this->call( 'getProfiles', array() );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{status: string, data?: array<string, mixed>, error_message?: string}
	 */
	public function create_profile( array $data ): array {
		/** @var array{status: string, data?: array<string, mixed>, error_message?: string} */
		return $this->call( 'createProfile', array( $data ), $this->restClient !== null );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{status: string, data?: array<string, mixed>, error_message?: string}
	 */
	public function update_profile( string $id, array $data ): array {
		/** @var array{status: string, data?: array<string, mixed>, error_message?: string} */
		return $this->call( 'updateProfile', array( $id, $data ), $this->restClient !== null );
	}

	/**
	 * @return array{status: string, error_message?: string}
	 */
	public function delete_profile( string $id ): array {
		/** @var array{status: string, error_message?: string} */
		return $this->call( 'deleteProfile', array( $id ), $this->restClient !== null );
	}

	/**
	 * @return array{status: string, error_message?: string}
	 */
	public function assign_profile( string $user, string $profile ): array {
		/** @var array{status: string, error_message?: string} */
		return $this->call(
			'assignProfile',
			array( $user, $profile )
		);
	}

	/**
	 * @return array{status: string, error_message?: string}
	 */
	public function remove_user_profile( string $user, string $profile ): array {
		/** @var array{status: string, error_message?: string} */
		return $this->call(
			'removeProfile',
			array( $user, $profile )
		);
	}

	/**
	 * @return array{status: string, error_message?: string}
	 */
	public function remove_user_all_profiles( string $user ): array {
		$this->record( 'remove_user_all_profiles', array( 'user' => $user ) );
		/** @var array{status: string, error_message?: string} */
		return $this->call( 'removeAllProfiles', array( $user ) );
	}

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string}
	 */
	public function get_user_profiles(): array {
		/** @var array{status: string, data?: array<int, array<string, mixed>>, error_message?: string} */
		return $this->call( 'getUserProfiles', array() );
	}

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string}
	 */
	public function get_active_sessions(): array {
		/** @var array{status: string, data?: array<int, array<string, mixed>>, error_message?: string} */
		return $this->call( 'getSessions', array(), $this->binaryClient !== null );
	}

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string}
	 */
	public function get_user_usage_counters(): array {
		/** @var array{status: string, data?: array<int, array<string, mixed>>, error_message?: string} */
		return $this->call_binary_only( 'getUserUsageCounters' );
	}

	// ---------------------------------------------------------------------
	// Router inventory (REST-only)

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string}
	 */
	public function get_routers(): array {
		/** @var array{status: string, data?: array<int, array<string, mixed>>, error_message?: string} */
		return $this->call_rest_only( 'getRouters' );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{status: string, data?: array<string, mixed>, error_message?: string}
	 */
	public function create_router( array $data ): array {
		/** @var array{status: string, data?: array<string, mixed>, error_message?: string} */
		return $this->call_rest_only( 'createRouter', array( $data ) );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{status: string, data?: array<string, mixed>, error_message?: string}
	 */
	public function update_router( string $id, array $data ): array {
		/** @var array{status: string, data?: array<string, mixed>, error_message?: string} */
		return $this->call_rest_only( 'updateRouter', array( $id, $data ) );
	}

	/**
	 * @return array{status: string, error_message?: string}
	 */
	public function delete_router( string $id ): array {
		/** @var array{status: string, error_message?: string} */
		return $this->call_rest_only( 'deleteRouter', array( $id ) );
	}

	// ---------------------------------------------------------------------
	// Internal helpers

	private function initialise_clients(): void {
		// In test mode, skip actual client initialization to avoid network connections
		if ( defined( 'MWP_TEST_SUITE' ) || defined( 'WP_TESTS_DOMAIN' ) ) {
			// Create a minimal mock client that satisfies the interface and records calls
			$this->primaryClient = new class () implements RouterClientInterface {
				public function getUsers(): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'get_users',
						'payload' => array(),
					);
					return array(
						'status' => 'ok',
						'data'   => array(),
					);
				}
				public function createUser( array $data ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'add_user',
						'payload' => $data,
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function updateUser( string $id_or_login, array $data ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'update_user',
						'payload' => array(
							'id'   => $id_or_login,
							'data' => $data,
						),
					);

					// Check for simulated failures after recording the call
					$remaining = 0;
					if ( isset( MikroTik_API::$failures['update_user'] ) ) {
						$remaining = MikroTik_API::$failures['update_user'];
					}
					if ( $remaining > 0 ) {
						$failures                = MikroTik_API::$failures;
						$failures['update_user'] = $remaining - 1;
						MikroTik_API::$failures  = $failures;
						return array(
							'status'        => 'error',
							'error_message' => 'Simulated failure',
						);
					}

					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function deleteUser( string $id_or_login ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'delete_user',
						'payload' => array( 'id' => $id_or_login ),
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function getProfiles(): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'get_profiles',
						'payload' => array(),
					);
					return array(
						'status' => 'ok',
						'data'   => array(),
					);
				}
				public function createProfile( array $data ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'create_profile',
						'payload' => $data,
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function updateProfile( string $id_or_name, array $data ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'update_profile',
						'payload' => array(
							'id'   => $id_or_name,
							'data' => $data,
						),
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function deleteProfile( string $id_or_name ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'delete_profile',
						'payload' => array( 'id' => $id_or_name ),
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function getGroups(): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'get_groups',
						'payload' => array(),
					);
					return array(
						'status' => 'ok',
						'data'   => array(),
					);
				}
				public function createGroup( array $data ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'create_group',
						'payload' => $data,
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function updateGroup( string $id_or_name, array $data ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'update_group',
						'payload' => array(
							'id'   => $id_or_name,
							'data' => $data,
						),
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function deleteGroup( string $id_or_name ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'delete_group',
						'payload' => array( 'id' => $id_or_name ),
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function assignProfile( string $username, string $profile ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'assign_profile',
						'payload' => array(
							'user'    => $username,
							'profile' => $profile,
						),
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function removeProfile( string $username, string $profile ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'remove_user_profile',
						'payload' => array(
							'user'    => $username,
							'profile' => $profile,
						),
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function removeAllProfiles( string $username ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'remove_user_all_profiles',
						'payload' => array( 'user' => $username ),
					);
					return array(
						'status' => 'ok',
						'data'   => 0,
					);
				}
				public function getSessions(): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'get_active_sessions',
						'payload' => array(),
					);
					return array(
						'status' => 'ok',
						'data'   => array(),
					);
				}
				public function getUserProfiles(): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'get_user_profiles',
						'payload' => array(),
					);
					return array(
						'status' => 'ok',
						'data'   => array(),
					);
				}
				public function getUserUsageCounters(): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'get_user_usage_counters',
						'payload' => array(),
					);
					return array(
						'status' => 'ok',
						'data'   => array(),
					);
				}
				public function getRouters(): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'get_routers',
						'payload' => array(),
					);
					return array(
						'status' => 'ok',
						'data'   => array(),
					);
				}
				public function createRouter( array $data ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'create_router',
						'payload' => $data,
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function updateRouter( string $id, array $data ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'update_router',
						'payload' => array(
							'id'   => $id,
							'data' => $data,
						),
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
				public function deleteRouter( string $id ): array {
					MikroTik_API::$calls[] = array(
						'method'  => 'delete_router',
						'payload' => array( 'id' => $id ),
					);
					return array(
						'status' => 'ok',
						'data'   => true,
					);
				}
			};
			return;
		}

		$this->routeros = $this->build_routeros();
		if ( $this->routeros ) {
			$this->binaryClient = new BinaryRouterClient( $this->routeros );
		}

		$this->umRest = $this->build_um_rest();
		if ( $this->umRest ) {
			$this->restClient = new RestRouterClient( $this->umRest );
		}

		if ( $this->restClient && isset( $this->capabilities['rest'] ) && is_array( $this->capabilities['rest'] ) && isset( $this->capabilities['rest']['available'] ) && ! empty( $this->capabilities['rest']['available'] ) ) {
			$this->primaryClient  = $this->restClient;
			$this->fallbackClient = $this->binaryClient;
		} elseif ( $this->binaryClient ) {
			$this->primaryClient  = $this->binaryClient;
			$rest_configured      = isset( $this->capabilities['rest'] ) && is_array( $this->capabilities['rest'] ) && isset( $this->capabilities['rest']['configured'] ) && ! empty( $this->capabilities['rest']['configured'] );
			$this->fallbackClient = $this->restClient && $rest_configured ? $this->restClient : null;
		} elseif ( $this->restClient ) {
			$this->primaryClient  = $this->restClient;
			$this->fallbackClient = null;
		} else {
			throw new RuntimeException( 'No router transports available' );
		}
	}

	/**
	 * @param array<mixed> $args
	 * @return array<string, mixed>
	 */
	/**
	 * @param array<mixed> $args
	 */
	/**
	 * @param array<array-key, mixed> $args
	 * @return array<string, mixed>
	 */
	private function call( string $method, array $args, bool $allowFallback = true ): array {
		$testMode = defined( 'MWP_TEST_SUITE' ) || defined( 'WP_TESTS_DOMAIN' );
		$response = null;
		if ( $testMode ) {
			$methodKey = $this->getMethodFailureKey( $method );
			if ( null !== $methodKey && '' !== $methodKey && isset( self::$failures[ $methodKey ] ) && self::$failures[ $methodKey ] > 0 ) {
				self::$failures[ $methodKey ] = self::$failures[ $methodKey ] - 1;
				$response                     = array(
					'status'        => 'error',
					'error_message' => 'Simulated failure',
				);
			}
		}

		if ( null === $response ) {
			if ( $testMode && ! $this->usingInjectedClients ) {
				return array(
					'status' => 'ok',
					'data'   => true,
				);
			}
			if ( ! $this->primaryClient ) {
				return array(
					'status'        => 'error',
					'error_message' => 'No primary client available',
				);
			}
			$response = $this->invoke( $this->primaryClient, $method, $args );
		}
		if ( $allowFallback && $this->primaryClient && $this->should_fallback( $this->primaryClient, $response ) && $this->fallbackClient ) {
			$this->log_fallback( $method, $response );
			// At this point we know fallbackClient is not null due to the condition above
			assert( $this->fallbackClient instanceof RouterClientInterface );
			$response = $this->invoke( $this->fallbackClient, $method, $args );
		}
		return $response;
	}

	/**
	 * @param array<array-key, mixed> $args
	 * @return array<string, mixed>
	 */
	private function call_binary_only( string $method, array $args = array() ): array {
		if ( ! $this->binaryClient ) {
			return array(
				'status'        => 'error',
				'error_code'    => 'no_binary_transport',
				'error_message' => 'Binary transport unavailable',
			);
		}
		return $this->invoke( $this->binaryClient, $method, $args );
	}

	/**
	 * @param array<array-key, mixed> $args
	 * @return array<string, mixed>
	 */
	private function call_rest_only( string $method, array $args = array() ): array {
		if ( ! $this->restClient ) {
			return array(
				'status'        => 'error',
				'error_code'    => 'no_rest_transport',
				'error_message' => 'REST transport unavailable',
			);
		}
		return $this->invoke( $this->restClient, $method, $args );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	/**
	 * @param array<array-key, mixed> $args
	 * @return array<string, mixed>
	 */
	private function invoke( RouterClientInterface $client, string $method, array $args ): array {
		if ( ! method_exists( $client, $method ) ) {
			return array(
				'status'        => 'error',
				'error_code'    => 'method_unsupported',
				'error_message' => 'Router client does not support ' . $method,
			);
		}
		/** @var array<string, mixed> $result */
		$result = $client->{$method}( ...$args );
		return $result;
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function should_fallback( RouterClientInterface $client, array $response ): bool {
		if ( $client !== $this->restClient ) {
			if ( ! $this->usingInjectedClients || $client !== $this->primaryClient || ! $this->fallbackClient ) {
				return false;
			}
		}
		return ( $response['status'] ?? '' ) !== 'ok';
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function log_fallback( string $method, array $response ): void {
		if ( ! class_exists( 'MUMB_Logger' ) ) {
			return;
		}
		MUMB_Logger::log(
			'warning',
			'router_fallback',
			'Falling back to binary transport for ' . $method,
			array(
				'error_code'    => $response['error_code'] ?? null,
				'error_message' => $response['error_message'] ?? null,
			)
		);
	}

	private function build_routeros(): ?MUMB_RouterOS_API {
		if ( empty( $this->opts['host'] ) || empty( $this->opts['username'] ) || empty( $this->opts['password'] ) ) {
			return null;
		}
		try {
			$host       = is_scalar( $this->opts['host'] ) ? (string) $this->opts['host'] : '';
			$port       = is_numeric( $this->opts['port'] ) ? (int) $this->opts['port'] : 8728;
			$username   = is_scalar( $this->opts['username'] ) ? (string) $this->opts['username'] : '';
			$password   = is_scalar( $this->opts['password'] ) ? (string) $this->opts['password'] : '';
			$ssl        = ! empty( $this->opts['ssl'] );
			$timeout    = is_numeric( $this->opts['api_timeout'] ) ? (int) $this->opts['api_timeout'] : 8;
			$verify_ssl = isset( $this->opts['verify_ssl'] ) ? (bool) $this->opts['verify_ssl'] : true;

			return new MUMB_RouterOS_API(
				$host,
				$port,
				$username,
				$password,
				$ssl,
				$timeout,
				$verify_ssl
			);
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'error', 'router_binary_init', $e->getMessage() );
			}
			return null;
		}
	}

	private function build_um_rest(): ?MUMB_UM_REST {
		if ( empty( $this->opts['um_rest'] ) || empty( $this->opts['um_rest_base'] ) ) {
			return null;
		}
		try {
			$um_rest_base = is_scalar( $this->opts['um_rest_base'] ) ? (string) $this->opts['um_rest_base'] : '';
			$username     = is_scalar( $this->opts['username'] ) ? (string) $this->opts['username'] : '';
			$password     = is_scalar( $this->opts['password'] ) ? (string) $this->opts['password'] : '';
			$verify_ssl   = isset( $this->opts['verify_ssl'] ) ? (bool) $this->opts['verify_ssl'] : true;
			$timeout      = is_numeric( $this->opts['api_timeout'] ) ? (int) $this->opts['api_timeout'] : 8;

			return new MUMB_UM_REST(
				$um_rest_base,
				$username,
				$password,
				$verify_ssl,
				$timeout
			);
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MUMB_Logger' ) ) {
				MUMB_Logger::log( 'error', 'router_rest_init', $e->getMessage() );
			}
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $opts
	 * @return array<string, mixed>
	 */
	private function normalise_opts( array $opts ): array {
		if ( empty( $opts ) ) {
			$opts = SettingsService::get_router_credentials();
		}
		$defaults       = array(
			'host'            => '',
			'port'            => 8728,
			'username'        => '',
			'password'        => '',
			'ssl'             => false,
			'verify_ssl'      => true,
			'api_timeout'     => 8,
			'retry_max'       => 3,
			'retry_base_ms'   => 200,
			'retry_jitter_ms' => 100,
			'um_rest'         => true,
			'um_rest_base'    => null,
		);
		$merged         = array_merge( $defaults, $opts );
		$merged['port'] = is_numeric( $merged['port'] ) ? (int) $merged['port'] : 8728;
		if ( $merged['port'] <= 0 ) {
			$merged['port'] = 8728;
		}
		$merged['api_timeout'] = is_numeric( $merged['api_timeout'] ) ? max( 1, (int) $merged['api_timeout'] ) : 8;
		$merged['ssl']         = (bool) $merged['ssl'];
		// Always use User Manager
		$merged['um_rest']     = true;
		$verify_ssl_enabled   = isset( $merged['verify_ssl'] ) && $merged['verify_ssl'] !== false && $merged['verify_ssl'] !== '' && $merged['verify_ssl'] !== 0;
		$merged['verify_ssl'] = $verify_ssl_enabled;
		// No custom CA path - use system defaults
		return $merged;
	}

	// Test support methods - only used during testing

	/**
	 * Record a method call for testing purposes.
	 *
	 * @param string               $method
	 * @param array<string, mixed> $payload
	 */
	protected function record( string $method, array $payload = array() ): void {
		if ( defined( 'MWP_TEST_SUITE' ) || defined( 'WP_TESTS_DOMAIN' ) ) {
			self::$calls[] = array(
				'method'  => $method,
				'payload' => $payload,
			);
		}
	}

	/**
	 * Check if a method should fail for testing purposes.
	 *
	 * @param string $key
	 * @return array<string, mixed>|null
	 */

	// Add camelCase method aliases for backward compatibility with MWP_REST class
	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string}
	 */
	public function getUsers(): array {
		return $this->get_users();
	}

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string}
	 */
	public function getProfiles(): array {
		return $this->get_profiles();
	}

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string}
	 */
	public function getGroups(): array {
		return $this->get_groups();
	}

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string}
	 */
	public function getUserProfiles(): array {
		return $this->get_user_profiles();
	}

	/**
	 * @return array{status: string, data?: array<int, array<string, mixed>>, error_message?: string}
	 */
	public function getRouters(): array {
		return $this->get_routers();
	}
}
