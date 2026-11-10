<?php

use MUMB\Clients\RouterClientInterface;

class MikroTik_API {
    public static array $fixtures = [
        'users' => __DIR__ . '/../fixtures/users.json',
        'profiles' => __DIR__ . '/../fixtures/profiles.json',
        'groups' => __DIR__ . '/../fixtures/groups.json',
        'user_profiles' => __DIR__ . '/../fixtures/user-profiles.json'
    ];

    public static array $failures = [];
    public static array $calls = [];

    public function __construct(array $opts = []) {
        // Log constructor call to verify stub is being used
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[STUB] MikroTik_API constructor called with stub class' );
        }
    }

    protected function maybeFail(string $key) : ?array {
        if (!empty(self::$failures[$key])) {
            self::$failures[$key]--;
            return [ 'status' => 'error', 'error_message' => 'Simulated failure' ];
        }
        return null;
    }

    protected function load(string $key) : array {
        $file = self::$fixtures[$key] ?? null;
        if (!$file || !file_exists($file)) {
            return [];
        }
        $json = file_get_contents($file);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    protected function record(string $method, array $payload = []) : void {
        self::$calls[] = [ 'method' => $method, 'payload' => $payload ];
    }

    public function get_users() : array {
        $this->record('get_users');
        if ($fail = $this->maybeFail('get_users')) {
            return $fail;
        }
        return [ 'status' => 'ok', 'data' => $this->load('users') ];
    }
    public function get_profiles() : array {
        $this->record('get_profiles');
        return [ 'status' => 'ok', 'data' => $this->load('profiles') ];
    }
    public function get_groups() : array {
        $this->record('get_groups');
        return [ 'status' => 'ok', 'data' => $this->load('groups') ];
    }
    public function create_group(array $data) : array {
        $this->record('create_group', $data);
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function update_group(string $id_or_name, array $data) : array {
        $this->record('update_group', [ 'id' => $id_or_name, 'data' => $data ]);
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function delete_group(string $id_or_name) : array {
        $this->record('delete_group', [ 'id' => $id_or_name ]);
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function get_user_profiles() : array {
        $this->record('get_user_profiles');
        return [ 'status' => 'ok', 'data' => $this->load('user_profiles') ];
    }
    public function assign_profile(string $user, string $profile) : array {
        $this->record('assign_profile', compact('user', 'profile'));
        if ($fail = $this->maybeFail('assign_profile')) {
            return $fail;
        }
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function remove_user_profile(string $user, string $profile) : array {
        $this->record('remove_user_profile', compact('user', 'profile'));
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function remove_user_all_profiles(string $user) : array {
        $this->record('remove_user_all_profiles', [ 'user' => $user ]);
        return [ 'status' => 'ok', 'data' => 0 ];
    }
    public function add_user(array $data) : array {
        $this->record('add_user', $data);
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[STUB] add_user called with: ' . wp_json_encode( $data ) );
        }
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function update_user(string $id_or_login, array $data) : array {
        $this->record('update_user', [ 'id' => $id_or_login, 'data' => $data ]);
        if ($fail = $this->maybeFail('update_user')) {
            return $fail;
        }
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function delete_user(string $id_or_login) : array {
        $this->record('delete_user', [ 'id' => $id_or_login ]);
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function update_profile(string $id_or_name, array $data) : array {
        $this->record('update_profile', [ 'id' => $id_or_name, 'data' => $data ]);
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function delete_profile(string $id_or_name) : array {
        $this->record('delete_profile', [ 'id' => $id_or_name ]);
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function create_profile(array $data) : array {
        $this->record('create_profile', $data);
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function create_router(array $data) : array {
        $this->record('create_router', $data);
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function get_active_sessions() : array {
        $this->record('get_active_sessions');
        return [ 'status' => 'ok', 'data' => [] ];
    }
    public function get_user_usage_counters() : array {
        $this->record('get_user_usage_counters');
        return [ 'status' => 'ok', 'data' => [] ];
    }
    public function get_routers() : array {
        $this->record('get_routers');
        return [ 'status' => 'ok', 'data' => [] ];
    }
    public function update_router(string $id, array $data) : array {
        $this->record('update_router', [ 'id' => $id, 'data' => $data ]);
        return [ 'status' => 'ok', 'data' => true ];
    }
    public function delete_router(string $id) : array {
        $this->record('delete_router', [ 'id' => $id ]);
        return [ 'status' => 'ok', 'data' => true ];
    }

    public function get_client() : RouterClientInterface {
        return new class($this) implements RouterClientInterface {
            private MikroTik_API $api;
            public function __construct(MikroTik_API $api) {
                $this->api = $api;
            }
            public function getUsers(): array {
                return $this->api->get_users();
            }
            public function createUser(array $data): array {
                return $this->api->add_user($data);
            }
            public function updateUser(string $id_or_login, array $data): array {
                return $this->api->update_user($id_or_login, $data);
            }
            public function deleteUser(string $id_or_login): array {
                return $this->api->delete_user($id_or_login);
            }
            public function getProfiles(): array {
                return $this->api->get_profiles();
            }
            public function createProfile(array $data): array {
                return $this->api->create_profile($data);
            }
            public function updateProfile(string $id_or_name, array $data): array {
                return $this->api->update_profile($id_or_name, $data);
            }
            public function deleteProfile(string $id_or_name): array {
                return $this->api->delete_profile($id_or_name);
            }
            public function getGroups(): array {
                return $this->api->get_groups();
            }
            public function createGroup(array $data): array {
                return $this->api->create_group($data);
            }
            public function updateGroup(string $id_or_name, array $data): array {
                return $this->api->update_group($id_or_name, $data);
            }
            public function deleteGroup(string $id_or_name): array {
                return $this->api->delete_group($id_or_name);
            }
            public function assignProfile(string $username, string $profile): array {
                return $this->api->assign_profile($username, $profile);
            }
            public function removeProfile(string $username, string $profile): array {
                return $this->api->remove_user_profile($username, $profile);
            }
            public function removeAllProfiles(string $username): array {
                return $this->api->remove_user_all_profiles($username);
            }
            public function getSessions(): array {
                return $this->api->get_active_sessions();
            }
            public function getUserProfiles(): array {
                return $this->api->get_user_profiles();
            }
            public function getUserUsageCounters(): array {
                return $this->api->get_user_usage_counters();
            }
            public function getRouters(): array {
                return $this->api->get_routers();
            }
            public function createRouter(array $data): array {
                return $this->api->create_router($data);
            }
            public function updateRouter(string $id, array $data): array {
                return $this->api->update_router($id, $data);
            }
            public function deleteRouter(string $id): array {
                return $this->api->delete_router($id);
            }
        };
    }
}
