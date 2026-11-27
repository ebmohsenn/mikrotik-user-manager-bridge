# MikroTik User Manager Bridge

[![CI](https://github.com/mohsenebrahimizadeh-ai/mikrotik-user-manager-bridge/actions/workflows/ci.yml/badge.svg)](https://github.com/mohsenebrahimizadeh-ai/mikrotik-user-manager-bridge/actions/workflows/ci.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

A WordPress plugin that connects a MikroTik RouterOS **User Manager** to WordPress.
It keeps router users and profiles in sync with WordPress users, can create router
accounts automatically from WooCommerce orders, and gives administrators a React
dashboard inside wp-admin to manage users, profiles, routers and sync jobs.

> Status: works in production for its original author. This is the first public release, so
> expect rough edges. Please open an issue if something does not work for you.

## Features

- **RouterOS connectivity**: binary RouterOS API (8728 plain / 8729 TLS) with a REST fallback
  (RouterOS v7 `/rest`), retries with backoff and jitter, and a keep-alive/health check cron.
- **Two-way sync**: mirrors User Manager users, groups, profiles and user-profile assignments
  into WordPress tables. You choose whether the router or WordPress is authoritative.
- **Initial bootstrap sync**: a preview/commit wizard that matches existing router users to
  WordPress accounts and reports conflicts before it writes anything.
- **WooCommerce integration** (optional): map products to User Manager profiles. A completed
  order creates or extends the router user. WooCommerce Subscriptions renewals and
  cancellations are supported if that extension is installed.
- **Job queue**: router writes are queued and retried in the background (WP-Cron), with a
  queue panel in the dashboard.
- **Admin dashboard** (React): users, profiles, groups, user-profiles, routers, a sync diff,
  queue, logs, settings, and a separate router health dashboard with trend charts.
- **Multiple routers**: a server registry that tracks User Manager and VPN nodes and their status.
- **Resellers / affiliates** (optional): an `affiliate_partner` role whose holders can create and
  manage their own customers' router accounts.
- **Front-end shortcodes**: `[mikrotik_dashboard]` (customer self-service),
  `[mikrotik_profile_download]` (signed, expiring OpenVPN profile download), and
  `[mikrotik_server_status]`.
- **White-label branding**: the display name and logo in the admin UI and dashboard widgets
  come from your site title, or from a filter/option.
- **REST API** under `/wp-json/mwp/v1/` (see [docs/rest-api.md](docs/rest-api.md)).
- **Translations**: ships with a Persian (`fa_IR`) translation.

## Requirements

| Component | Version |
|-----------|---------|
| WordPress | 6.5 or newer |
| PHP | 8.1 or newer, with the `openssl` and `sockets` extensions |
| MikroTik RouterOS | v7.x with the User Manager package. Enable the API (`api` or `api-ssl`) service, or `www-ssl` for REST |
| WooCommerce | optional, for order-based provisioning |
| WooCommerce Subscriptions | optional (a paid extension), for renewals and cancellations |

## Installation

### From a release zip

1. Download the latest release zip from the
   [Releases page](https://github.com/mohsenebrahimizadeh-ai/mikrotik-user-manager-bridge/releases).
2. In wp-admin go to **Plugins → Add New → Upload Plugin**, upload the zip and activate it.

### From source

```bash
cd wp-content/plugins
git clone https://github.com/mohsenebrahimizadeh-ai/mikrotik-user-manager-bridge.git
```

Activate **MikroTik User Manager Bridge** in **Plugins**. The compiled admin assets are
committed in `admin/build/` and `admin/health-dashboard/`, so neither Composer nor npm is
needed at runtime. Activation creates the plugin's tables (`{prefix}mikrotik_*`) and schedules
its cron events.

## Configuration

1. On the router, create a dedicated API user. Give it only the policies it needs
   (`api`, `read`, `write`, plus `rest-api` if you use REST). Do not reuse `admin`.
2. Enable the API service. Use TLS (port 8729) whenever WordPress and the router are not on the
   same trusted network. See [docs/routeros-tls-setup.md](docs/routeros-tls-setup.md).
3. In wp-admin open the plugin's **Manager** menu, then **Settings**, and enter:
   - **Host**: router IP address or DNS name
   - **Port**: 8728 (plain) or 8729 (TLS)
   - **Username / Password**: the API user from step 1
   - **Use TLS/SSL / Verify TLS certificate**: keep verification on unless the router uses a
     self-signed certificate that WordPress does not trust
4. Click **Save & Connect**, then run the **Initial sync** in preview mode and review the results
   before you commit.
5. Optional: under **Profile mapping**, enter a JSON map of User Manager profile to WooCommerce
   product ID, for example `{"Basic": 123, "Premium": 456}`.
6. Optional: branding. The admin UI shows your site title by default. To use a different name or
   logo, use the `mumb_branding_defaults` filter or save the `mumb_branding` option
   (`{"name": "...", "logo_url": "..."}`). There is no settings screen for branding yet.

### wp-config.php constants

| Constant | Purpose |
|----------|---------|
| `MUMB_SENTRY_DSN` | Report plugin errors to Sentry. Only used when the Sentry PHP SDK is loaded on the site. |
| `MUMB_SKIP_COMPOSER_AUTOLOAD` | Skip the plugin's `vendor/autoload.php`. Mainly useful for tests. |

### Filters

| Filter | Purpose |
|--------|---------|
| `mumb_branding_defaults` | Default brand name and logo. |
| `mumb_branding_tokens` | Literal tokens (default `{brand}`) that are replaced with the brand name in this plugin's translated strings. |
| `mumb_default_affiliate_owner` | User ID set as the affiliate owner of self-registered users. The default is `1`; return `0` to disable. |

## Screenshots

_Screenshots coming soon._

<!--
![Users table](docs/screenshots/users.png)
![Sync diff](docs/screenshots/sync-diff.png)
![Router health dashboard](docs/screenshots/health.png)
-->

## Development

```bash
composer install          # PHP dev tools (PHPUnit, PHPStan, PHPCS + WPCS, Brain Monkey)
composer test             # PHPUnit; uses lightweight WordPress stubs, no database needed
composer stan             # PHPStan (level 5, with a baseline in phpstan-baseline.neon)
composer cs               # PHPCS with the WordPress Coding Standards
composer lint             # php -l on every PHP file

cd admin
npm ci
npm run build             # rebuilds admin/build and admin/health-dashboard
npm test                  # Jest unit tests for the React admin
```

[GitHub Actions](.github/workflows/ci.yml) runs the PHP checks on PHP 8.1, 8.2 and 8.3, and runs
`npm ci`, `npm test` and `npm run build` on Node 20. The build step fails if the rebuilt bundles
differ from the committed `admin/build/` and `admin/health-dashboard/` files.

Two REST tests are skipped unless the WordPress core test library is available (set
`WP_TESTS_DIR`). `tests/rest-smoke.php` is a manual smoke test against a real WordPress install:
`WP_LOAD=/path/to/wp-load.php php tests/rest-smoke.php`.

## Security notes

- **Router credentials** are stored in the `mumb_settings` option, encrypted with AES-256-CBC
  using a key derived from your WordPress `AUTH_KEY` salt. Changing your salts makes the stored
  password unreadable, so you will need to enter it again. The encryption protects against
  casual database dumps. It does not protect against someone who can read `wp-config.php`.
- **Least privilege**: give the plugin a dedicated RouterOS user, and restrict the API service
  to your WordPress server's IP with `/ip service set api-ssl address=...` and firewall rules.
- **Use TLS** (8729 / `www-ssl`) across untrusted networks. Plain API on port 8728 sends
  credentials in clear text.
- **REST API**: all administrative endpoints require the `manage_options` capability.
  Self-service endpoints (`/self/*`) require a logged-in user, and changing a password also
  requires the current one. Reseller endpoints require administrators, the `affiliate_partner`
  role, or the `manage_affiliates` capability.
- **Profile downloads** use signed, expiring tokens.
- **Reporting vulnerabilities**: please do not open a public issue. Use GitHub's
  [*Report a vulnerability*](https://github.com/mohsenebrahimizadeh-ai/mikrotik-user-manager-bridge/security/advisories/new)
  (private security advisory) on this repository instead.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Copyright © 2026 Mohsen Ebrahimizadeh

This program is free software; you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation; either version 2 of the
License, or (at your option) any later version. See [LICENSE](LICENSE) for the full text.

SPDX-License-Identifier: `GPL-2.0-or-later`

MikroTik and RouterOS are trademarks of MikroTik SIA. This project is not affiliated with or
endorsed by MikroTik.
