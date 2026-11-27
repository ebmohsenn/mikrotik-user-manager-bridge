# Contributing

Thanks for helping improve MikroTik User Manager Bridge!

## Reporting bugs

Open an issue and include your WordPress, PHP and RouterOS versions, what you expected, what
happened, and any relevant lines from the plugin's **Logs** tab. **Remove router addresses,
usernames and passwords before you post.** Report security problems privately through GitHub's
*Report a vulnerability* button, not in a public issue.

## Development setup

```bash
git clone https://github.com/ebmohsenn/mikrotik-user-manager-bridge.git
cd mikrotik-user-manager-bridge
composer install
cd admin && npm ci
```

Symlink or clone the folder into `wp-content/plugins/` of a local WordPress install to try it out.

## Before you open a pull request

```bash
composer lint    # php -l on all PHP files
composer test    # PHPUnit
composer stan    # PHPStan; do not add new baseline entries
composer cs      # WordPress Coding Standards
cd admin && npm test && npm run lint
```

If you change anything under `admin/src/`, run `npm run build` and commit the updated
`admin/build/` and `admin/health-dashboard/` files, because users install the plugin without
running npm.

## Guidelines

- Keep each PR focused on one change, and add or update tests for any behaviour you change.
- Every REST route needs a real `permission_callback`. Never use `__return_true` for routes that
  read or change router or user data.
- Sanitize input, escape output, and use `$wpdb->prepare()` for SQL.
- Wrap user-facing strings in `__()` / `esc_html__()` with the `mikrotik-user-manager-bridge`
  text domain.
- Do not commit real router addresses, credentials or customer data, including in test fixtures.
- Add a line under `## [Unreleased]` in `CHANGELOG.md`.

By contributing you agree that your contributions are licensed under the GNU General Public License v2.0 or later
(GPL-2.0-or-later), the same license as the project.
