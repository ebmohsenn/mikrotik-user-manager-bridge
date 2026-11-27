# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
