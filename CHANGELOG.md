# Changelog

## 2.1.0 — 2026-11-06

### Added

- Account enumeration protection, enabled by default and switched off with
  `PORTCULLIS_HIDE_ACCOUNTS=false`: `/wp/v2/users` and `/wp/v2/users/{id}` are
  removed for users who cannot `edit_posts`, `?author=<id>` answers a 404
  instead of redirecting to the author's slug, author archives of accounts
  without published posts answer a 404, the users sitemap is dropped, and
  oEmbed responses and comment classes no longer carry the slug.
- `PORTCULLIS_AUTHOR_ARCHIVES=false` answers every author archive with a 404 and
  points author links to the home page, independently of
  `PORTCULLIS_HIDE_ACCOUNTS`. Off by default, since it changes public URLs.
- `portcullis/account_listing_capability` and `portcullis/author_link` filters.
- `wp portcullis status` reports the account enumeration protection.
- Integration tests for the account enumeration adapters.

### Upgrading

The protection is on as soon as the package is updated. Two changes can reach an
existing integration:

- **Anonymous `/wp/v2/users` requests answer `rest_no_route`.** A headless front
  end that reads author data without authenticating, `_embed` included, loses it:
  set `PORTCULLIS_HIDE_ACCOUNTS=false`, or authenticate it and widen
  `portcullis/account_listing_capability`.
- **`?author=<id>`, the users sitemap and the archives of accounts without posts
  answer a 404.** Links or sitemap submissions that point to them stop resolving.

## 2.0.1 — 2026-09-15

### Fixed

- The attempts table is also installed when an administrator opens an
  administration screen, so that sites managed without WP-CLI do not wait for a
  first login attempt.
- A table that cannot be created is now reported with an administration notice
  and in `wp portcullis status`, instead of only in the PHP error log.
- A failed installation is retried every 15 minutes instead of on every login
  attempt.
- A table dropped while its version was still recorded is installed again on
  the next request, instead of failing until `wp portcullis install` is run.

## 2.0.0 — 2026-09-15

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
