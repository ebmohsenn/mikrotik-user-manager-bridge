# MikroTik User Manager Bridge REST API

This plugin exposes a set of REST endpoints under `/wp-json/mwp/v1/` for managing MikroTik User Manager data. All endpoints require an authenticated WordPress user; most administrative routes require the `manage_options` capability.

## Authentication

Use WordPress cookies or application passwords to authenticate requests. Administrative routes will return `403` when the current user lacks `manage_options`.

## Common Response Structure

Successful requests return JSON structures with a top-level `success` flag and optional `data` payload. Failures respond with `success: false`, an explanatory `message`, and an appropriate HTTP status code (`4xx` for client errors, `5xx` for router/API failures).

## Endpoint Reference

### `GET /wp-json/mwp/v1/health`

Returns the current connection status, last synchronization metadata, and cached RouterOS information. Use this to confirm RouterOS connectivity before issuing write operations.

### `GET /wp-json/mwp/v1/diagnostics`

Provides an expanded diagnostic snapshot combining:

- `connection_state`: the raw values stored in the `mumb_connection_state` option.
- `sync_state`: last sync timestamp and duration from `mumb_sync_state`.
- `queue`: current job count and the earliest scheduled run timestamp (ISO8601).
- `server_info`: RouterOS version, identity, and latency cached in `mumb_server_info`.

This endpoint is useful for dashboards or monitoring checks prior to destructive syncs.

### `GET /wp-json/mwp/v1/sync/diff`

Produces a dry-run diff describing which groups, profiles, users, and user profile assignments need to be created, updated, or removed to make the router match WordPress. The plugin now hardens the MikroTik API calls—unexpected responses are logged through `MUMB_Logger` instead of causing fatal errors.

### `POST /wp-json/mwp/v1/sync/apply`

Pushes changes from WordPress to MikroTik. Accepts optional JSON body keys:

- `destructive` (`bool`): remove router entities absent from WordPress when true.
- `delete_scope` (`array`): limit deletions to particular entity types (`groups`, `profiles`, `users`, `user_profiles`).
- `delete_list` (`array`): explicit lists of entities to remove.

### `GET|POST|PATCH|DELETE /wp-json/mwp/v1/users`

- `GET`: list MikroTik users (requires `manage_options`). Optional filters via query string.
- `POST`: create a user; body accepts `username`, `password`, and optional `group`, `profile`, `comment`, `shared-users`, `disabled`.
- `PATCH /users/{id}`: update password/group/comment/flags or queue a profile assignment. Returns `400` if no changes were provided.
- `DELETE /users/{id}`: remove a user.

### `GET|POST|PATCH|DELETE /wp-json/mwp/v1/profiles`

CRUD operations for MikroTik profiles via the mirrored database.

### `GET|POST|PATCH|DELETE /wp-json/mwp/v1/groups`

CRUD operations for MikroTik groups via the mirrored database.

### `GET|POST|DELETE /wp-json/mwp/v1/user-profiles`

Manage profile assignments for users. `GET` supports `?live=1` to bypass the mirror and query RouterOS directly.

### `POST /wp-json/mwp/v1/groups/sync`

Forces a mirror refresh from RouterOS to WordPress, returning the latest mirrored groups.

### `GET /wp-json/mwp/v1/logs`

Fetch recent log entries stored in `mikrotik_logs`. Supports `level`, `action`, and `s` search parameters.

### `GET|POST|PATCH|DELETE /wp-json/mwp/v1/routers`

Manage router connection entries stored in WordPress.

### `GET /wp-json/mwp/v1/settings`

Retrieve configuration settings, with secrets masked.

### `POST /wp-json/mwp/v1/settings`

Update MikroTik connection settings. The password is encrypted at rest; send the plain text value.

### `POST /wp-json/mwp/v1/user-profiles/sync`

Triggers a user profile sync and returns the refreshed mirror data.

### `POST /wp-json/mwp/v1/self/location`

Allows logged-in users to change their own profile assignment.

## Smoke Tests

 A CLI smoke test script lives at `tests/rest-smoke.php`. Run it from the project root (with PHP and WordPress prerequisites satisfied) to exercise key REST routes:

```bash
php tests/rest-smoke.php
```

Set the environment variable `SMOKE_TEST_USER_ID` to impersonate a specific user:

```bash
SMOKE_TEST_USER_ID=5 php tests/rest-smoke.php
```

The script reports HTTP statuses and exits non-zero when any route returns `500` or `WP_Error`.
