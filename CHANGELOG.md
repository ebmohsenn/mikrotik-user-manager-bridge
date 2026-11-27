# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.2] - 2026-11-27

Security release. Upgrade, then open any WordPress admin page once so the clean-up below runs.

### Security
- **Passwords in the job queue.** With WooCommerce active, a WordPress password change or reset
  queued a router update that carried the new password in clear text. Because of a key mismatch
  the job always failed, and after five attempts its payload, password included, was copied to
  the `mumb_job_history` option, where it stayed. `GET /mwp/v1/queue` returned it to
  administrators, and `GET /mwp/v1/affiliate/queue` to an affiliate for users they owned. Now
  queued passwords are encrypted while they wait, decrypted only when the job runs, never written
  to the history and never returned by the API. Password changes of accounts that have no router
  user are no longer queued at all. On upgrade the plugin removes passwords from the existing
  history and encrypts any still in the queue. **If you ran 2.1.1 or earlier with WooCommerce,
  treat WordPress passwords changed in that time as exposed to anyone who could read your database
  or its backups, and reset the important ones.**
- **Router accounts without a password.** Accounts created from a completed order, or pushed to
  the router when WordPress is the authority, were created with no password. They now get a random
  one; the customer sets their own by changing or resetting their WordPress password, which is
  synced to the router. Check existing accounts on your router for empty passwords.
- **TLS certificate verification** for the User Manager REST sync followed the "Use TLS/SSL"
  switch instead of "Verify TLS certificate", so a site with the first off and the second on
  synced without verifying the router's certificate. Verification now follows its own setting
  everywhere, including the queue, the REST handlers and the REST health probe.
- The health check logged in to every server in the registry with the main router's credentials,
  in clear text on port 8728. It now logs in to the main router only (others get a reachability
  probe; see the `mumb_health_authenticate_host` filter) and never drops to clear text when TLS is
  configured. Profile downloads from the router use HTTPS on every port except 80.
- `tests/rest-smoke.php` could be requested over HTTP on sites that installed the plugin with
  `git clone`, and ran as user 1. It now refuses to run outside the command line, and `tests/`,
  `docs/` and the build sources are left out of release archives.
- The WordPress dashboard widgets (router host, API user, affiliate figures) were shown to every
  logged-in user. They are now for administrators only.
- Editing a router user through the REST API also changed the WordPress account with the same
  login, and `POST /mwp/v1/affiliates` could change any account's email, name and role. Both now
  need the WordPress capability for that account (`edit_user`, or `create_users` for new ones).
- Affiliates: creating a user whose login already belongs to someone else is refused, an
  affiliate's stored group list is enforced on create and update, and the login that was checked
  is the login that is changed.
- `/mwp/v1/self/group`, `/self/location` and `/self/password` are rate limited, and a customer
  can no longer overwrite their own affiliate owner. See the new `mumb_user_allowed_groups` filter
  to limit which groups a customer may choose.
- `GET /mwp/v1/routers` no longer returns the RADIUS shared secret.
- The settings page no longer writes the form, router password included, to the browser console,
  and the password fields are masked.
- The host name passed to `ping` is validated first.

### Fixed
- Creating users, groups, profiles and routers through the API facade failed with
  "Unknown named parameter"; this broke user creation from the admin and affiliate screens.
- WooCommerce renewals and password changes never reached the router (the job looked for
  `username`, the integration sent `user`).
- `[mikrotik_profile_download]` and `[mikrotik_server_status]` were never registered: their code
  had ended up inside a script block of the dashboard shortcode, which also broke its
  "change location" form. The status shortcode shows data to administrators only, because the
  endpoint behind it requires `manage_options`.
- `GET /mwp/v1/logs` always returned an empty list.
- Saving settings without the `ssl` field switched TLS off.
- Synced WordPress accounts no longer have their display name reset to their login on every sync.
- Deactivating the plugin stops its scheduled jobs, and uninstalling removes all of its options.

### Changed
- CI actions are pinned to commit SHAs, and Dependabot is configured for npm, Composer and Actions.
- Admin build dependencies updated; `npm audit` reports no high or critical advisories.

## [2.1.1] - 2026-11-27

### Fixed
- SQL queries in the `/sync/diff`, `/user-profiles`, profiles and settings REST endpoints passed
  table names as quoted `%s` values, which produced invalid SQL. They now use the `%i`
  identifier placeholder.
- Loading the main plugin file twice no longer warns about already-defined `MUMB_*` constants.

### Changed
- Added a GitHub Actions CI workflow (PHP 8.1 to 8.3 lint, PHPCS, PHPStan, PHPUnit; Node 20 Jest
  tests, build, and a check that the committed admin bundles match the source).

## [2.1.0] - 2026-11-10

First public open-source release. Versions before this one were developed privately.

### Security
- `GET /mwp/v1/user-profiles` and `GET /mwp/v1/users/profiles/live` were reachable without
  authentication and exposed router usernames, profiles and expiry dates. They now require
  `manage_options`.
- All routes that used `__return_true` and checked capabilities inside the callback now enforce
  `manage_options` in `permission_callback`.

### Fixed
- `includes/class-routeros-client.php` printed a stray `/user-manager` before `<?php` on every
  request, which could break redirects and REST/JSON responses.
- WooCommerce integration: removed the call to `is_plugin_active()`, which is not loaded on
  front-end requests. Initialisation now runs on `plugins_loaded`, so WooCommerce is detected
  regardless of plugin load order.
- Branding: `gettext` filters now only touch this plugin's text domain, not every string on the site.

### Changed
- Licensed under the GNU General Public License v2.0 or later (GPL-2.0-or-later), the standard
  license for WordPress plugins.
- The repository layout is now a standalone plugin: the plugin root is the repository root, and
  Composer's `vendor/` lives inside the plugin.
- Sentry reporting is opt-in through the `MUMB_SENTRY_DSN` constant, and `sentry/sentry` is a
  Composer *suggestion* instead of a hard requirement.
- New `mumb_branding_tokens` filter (default `{brand}`) and `mumb_default_affiliate_owner` filter
  (default `1`, return `0` to disable).
- The default brand name falls back to the site title, then "MikroTik Bridge".
