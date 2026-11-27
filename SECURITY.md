# Security policy

## Reporting a vulnerability

Please report security problems privately, not in a public issue: use
[**Report a vulnerability**](https://github.com/ebmohsenn/mikrotik-user-manager-bridge/security/advisories/new)
on this repository's **Security** tab.

Include the plugin, WordPress, PHP and RouterOS versions, the steps that reproduce the problem and
the impact you expect. Remove router addresses, usernames and passwords from anything you send.
You will get an acknowledgement within a few days, and credit in the changelog when the fix ships,
if you want it.

## Supported versions

Only the latest release receives security fixes. Security fixes are listed under **Security** in
[CHANGELOG.md](CHANGELOG.md).

## Deployment notes

The plugin stores a RouterOS login and can create, change and disable router accounts, so treat a
WordPress administrator of the site as an administrator of the router's User Manager. See
"Security notes" in the [README](README.md) for the hardening the plugin expects: a dedicated RouterOS
user, TLS with certificate verification, and the API service restricted to the WordPress server.
