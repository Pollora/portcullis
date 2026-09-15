# Changelog

## 2.0.0 — unreleased

The package is renamed `pollora/portcullis` and gains brute-force protection.

### Added

- Brute-force protection, enabled by default: failed logins are counted per
  address and per account, and lock them out in two tiers (4 failures for
  20 minutes per address, the 4th lockout for 24 hours; 20 failures per account).
  Covers the login screen, XML-RPC, REST application passwords and pluggable
  `wp_authenticate()` replacements.
- Client address resolution through trusted proxies, reading `X-Forwarded-For`
  from right to left. IPv6 addresses are grouped on their /64.
- Counters stored in a dedicated table, keyed by HMAC-SHA256 of the address or
  the login, with atomic increments and conditional lockouts. Storage is
  replaceable through `AttemptStorePort`, whose behaviour is pinned by a contract
  suite.
- Generic login errors that no longer tell unknown accounts from wrong passwords.
- `wp portcullis lockouts`, `unlock`, `install` and `purge`.
- `portcullis/lockout_message` filter and `portcullis/locked_out` action.
- An integration suite running the storage contract on a real WordPress
  installation.

### Changed

- Package renamed from `pollora/hidden-login` to `pollora/portcullis`; the two
  conflict.
- Namespace `Pollora\HiddenLogin` becomes `Pollora\Portcullis`, and the
  composition root `HiddenLogin` becomes `Portcullis`.
- Configuration keys `PORTCULLIS_LOGIN_SLUG` and `PORTCULLIS_ENABLED`.
- Filters renamed `portcullis/allowed_default_actions`,
  `portcullis/public_admin_scripts` and `portcullis/render_theme_404`.
- WP-CLI command renamed `wp portcullis`.

### Deprecated

Still working, to be removed in 3.0:

- `HIDDEN_LOGIN_SLUG` and `HIDDEN_LOGIN_ENABLED`, read after the new keys.
- `hidden_login/*` filters, applied before the new ones.
- `wp hidden-login`.
- `Pollora\HiddenLogin\HiddenLogin::boot()` and the `Pollora\HiddenLogin\Port\Out`
  interfaces.

### Breaking

- `HookRegistrarPort` has a new `doAction()` method. Only custom implementations
  of the port are affected.

## 1.0.2

- Stop core's convenience redirects from disclosing the login slug.

## 1.0.1

- License the package under MIT.

## 1.0.0

- Initial release.
