# pollora/portcullis

Secures the WordPress login, with two independent features:

- **Hidden login** — serves the login screen from a secret URL, and answers
  `wp-login.php` and `wp-admin/` with a genuine 404.
- **Brute-force protection** — locks out addresses and accounts after repeated
  failed logins, on the login screen, XML-RPC and REST application passwords alike.

Unlike the usual "rename wp-login" plugins, the hidden login does not reimplement
the login screen: it includes the WordPress file itself from the secret URL. Every
authentication flow — password recovery, two-factor, single sign-on, privacy
request confirmation — therefore keeps working exactly as WordPress and your
plugins intend, at a different address.

> Formerly `pollora/hidden-login`. See [Upgrading from pollora/hidden-login](#upgrading-from-pollorahidden-login).

## Installation

```bash
composer require pollora/portcullis
```

That is the whole installation. The package registers itself through Composer's
`autoload.files`, which on a WordPress installation runs from `wp-config.php` —
well before `add_action()` even exists. It therefore schedules its own boot on
`muplugins_loaded` through WordPress' pre-initialised hook array, the mechanism
`wp-settings.php` normalises with `WP_Hook::build_preinitialized_hooks()`. No
must-use plugin, no service provider, no call to add anywhere.

Hosts that need control over the moment, or want to inject their own adapters,
can still call the composition root explicitly — it is idempotent, so doing both
is harmless:

```php
\Pollora\Portcullis\Portcullis::boot();
```

## Configuration

Every value is read from a PHP constant first and from the environment as a
fallback. On Bedrock, expose the ones you set as constants in
`config/application.php`:

```php
Config::define('PORTCULLIS_LOGIN_SLUG', env('PORTCULLIS_LOGIN_SLUG') ?: null);
```

**Nothing is stored in the database, on purpose.** Options would travel with
production dumps restored on staging and local machines, where they would either
leak production secrets or lock developers out.

### Package

```dotenv
PORTCULLIS_ENABLED=false   # optional kill switch for both features, enabled by default
```

It defaults to enabled: an installation that pulled the package in has opted in,
so turning it off has to be deliberate. An unrecognised value counts as enabled,
because a typo must not silently drop a security control.

### Hidden login

```dotenv
PORTCULLIS_LOGIN_SLUG=acces-prive
```

The slug must be a single URL segment: lowercase letters, digits, hyphens and
underscores, at least 5 characters, not one of the paths WordPress already owns.

**No slug means no interception.** The feature stays completely dormant, and
WordPress behaves as it always did. That fail-open is deliberate: a freshly
provisioned environment or a missing `.env` entry must never lock everybody out
of an installation nobody can reach a terminal on.

### Brute-force protection

Enabled by default, with the defaults of
[Limit Login Attempts Reloaded](https://wordpress.org/plugins/limit-login-attempts-reloaded/)
plus a per-account threshold. Every key is optional:

```dotenv
PORTCULLIS_THROTTLE_ENABLED=true

# Per address
PORTCULLIS_MAX_RETRIES=4                  # failures before a lockout
PORTCULLIS_LOCKOUT_DURATION=1200          # 20 minutes
PORTCULLIS_MAX_LOCKOUTS=4                 # the 4th lockout is a long one (0 disables the long tier)
PORTCULLIS_LONG_LOCKOUT_DURATION=86400    # 24 hours
PORTCULLIS_RETRIES_VALIDITY=86400         # counters are forgotten 24 hours after the last failure

# Per account, all addresses combined
PORTCULLIS_ACCOUNT_MAX_RETRIES=20         # 0 counts per address only
PORTCULLIS_ACCOUNT_LOCKOUT_DURATION=1200

# Network
PORTCULLIS_TRUSTED_PROXIES=               # CIDR list: proxies whose X-Forwarded-For is believed
PORTCULLIS_ALLOWLIST=                     # CIDR list: addresses never locked out

# Privacy
PORTCULLIS_SECRET=                        # at least 32 bytes; derived from the WordPress keys when empty
PORTCULLIS_GENERIC_LOGIN_ERRORS=true      # stop telling unknown accounts from wrong passwords
```

A rejected value — a threshold that is not an integer, a lockout longer than the
validity window, a malformed range — does not switch the protection off: the
defaults apply, and the error is logged and shown as an admin notice.

## Behaviour

### Hidden login

| Request | Response |
| --- | --- |
| `/<slug>` | The native login screen, with every `action` it supports |
| `wp-login.php` | 404, for everyone |
| `/login`, `/login.php`, `/wp-login.php` at the site root | 404 — core's `wp_redirect_admin_locations()` would otherwise redirect them to the slug |
| `/admin`, `/dashboard` | Redirected to `wp-admin`, as core does — they disclose nothing |
| `wp-admin/*`, anonymous | 404, emitted **before** `auth_redirect()` could leak the slug |
| `wp-admin/*`, authenticated | Untouched |
| `wp-admin/admin-ajax.php`, `admin-post.php` | Untouched — the public site depends on them |
| WP-CLI, WP-Cron | Untouched |

The following keep working through the secret URL, because WordPress builds all
of them with `site_url()` / `network_site_url()` and the `login` scheme:

`?action=lostpassword` · `?action=rp` and `?action=resetpass` (the link in the
reset email) · `?action=logout` · `?action=register` · `?action=postpass`
(password-protected posts) · `?action=confirmaction` (privacy requests) ·
`?checkemail=confirm|registered` · `interim-login=1` (the expired-session modal)
· the `wp_new_user_notification` email.

### Brute-force protection

Failures are counted on two independent scopes:

- **per address** — every 4 failures lock the address out for 20 minutes, and
  the 4th lockout lasts 24 hours. IPv6 addresses are grouped on their /64, which
  a single subscriber can rotate through for free.
- **per account** — 20 failures on one account, from any number of addresses,
  lock that account out. This is what stops a botnet spreading its guesses across
  thousands of addresses, each staying under the per-address threshold. The
  threshold is higher so that nobody can lock a colleague out by mistyping their
  login a few times. Attempts by username and by email address count together.

While locked out, an attempt is refused **before** its password is checked: the
refusal costs one primary key lookup and no password hashing, the right password
gets the attacker nowhere, and the response carries a `429` status and a message
saying how long to wait. Refused attempts are not counted.

| Entry point | Covered by |
| --- | --- |
| Login screen, `wp_signon()` | `authenticate` filter |
| XML-RPC | `authenticate` filter |
| REST API with an application password | `determine_current_user` and `application_password_failed_authentication` |
| Pluggable `wp_authenticate()` replacements | `wp_login_failed` fallback |

A successful login clears the failures **of the account only**. Clearing the
address as well would let anyone holding an account — on a site with open
registration, anyone — reset their own address between two bursts of guesses on
someone else's account.

#### Client address

By default the TCP peer, `REMOTE_ADDR`, is the client. Behind a reverse proxy or a
CDN, list the proxies in `PORTCULLIS_TRUSTED_PROXIES`: `X-Forwarded-For` is then
read **from right to left**, past trusted proxies only, and the first address
that is not one of them is the client.

The direction matters. Proxies *append* to the header, and the client writes
whatever it wants on its left. Limit Login Attempts Reloaded reads the leftmost
valid address, which lets an attacker send a different made-up identity with
every request and never be locked out.

#### Storage

Counters live in a dedicated table, `{base_prefix}portcullis_attempts`: one
compact row per address or account, found by primary key. No command is needed
to create it:

- it is installed on the first login attempt, or as soon as an administrator
  opens any administration screen, whichever comes first;
- if it cannot be created — typically a database user without the `CREATE`
  privilege — an administration notice says so, with the database error, and the
  installation is retried every 15 minutes rather than on every request;
- if the table disappears while its version is still recorded — a partial
  restore, a table dropped by hand — it is installed again on the next request.

`wp portcullis install` does the same on demand, without waiting, which suits
deployment scripts.

Transients were ruled out: without a persistent object cache they land in
`wp_options`, two rows per key, with no index a purge could use — an attack would
bloat the one table every request reads.

Concurrency is handled by the database. Every failure is a single
`INSERT … ON DUPLICATE KEY UPDATE`, and a lockout is a conditional `UPDATE`
applied only while the threshold is still reached, so simultaneous failures can
neither lose increments nor stack lockouts. Expired counters are purged daily,
in batches, through WP-Cron.

Addresses and logins are never stored in clear: rows are keyed by an
HMAC-SHA256 under `PORTCULLIS_SECRET`. That is pseudonymisation — whoever holds
the secret can still test whether an address was recorded — but the table alone
reveals nothing, and a production dump restored elsewhere blocks nobody, because
the secret differs between environments.

Any other storage — Redis, say — plugs in by implementing `AttemptStorePort`:

```php
\Pollora\Portcullis\Portcullis::boot(store: new MyRedisAttemptStore());
```

Its expected behaviour is pinned down by the contract suite in `tests/Contract`,
which the MySQL adapter and the in-memory reference implementation both pass.

Storage errors fail open: the error is logged and logins keep working, rather
than a missing table locking everybody out.

#### Lockout log

Every lockout writes one line to the PHP error log — the only place the address
and the login appear in clear — in a format suitable for grepping or fail2ban:

```
[portcullis] lockout scope=ip ip=203.0.113.7 login="admin" until=2026-09-15T14:25:42Z tier=regular
```

## Operations

WP-CLI is never intercepted — it is the way back in when the slug is lost or an
administrator is locked out:

```bash
wp portcullis url                   # prints the effective login URL
wp portcullis status                # prints what is currently enforced
wp portcullis lockouts              # lists running lockouts
wp portcullis unlock 203.0.113.7    # lifts a lockout: an address, a login or a key
wp portcullis install               # creates or upgrades the table, for deployment scripts
wp portcullis purge                 # deletes expired counters now
```

`wp hidden-login` remains available as an alias.

## Extension points

| Hook | Type | Purpose |
| --- | --- | --- |
| `portcullis/allowed_default_actions` | filter | Actions still tolerated on `wp-login.php`, for third-party code that posts to it with a hard-coded URL. Empty by default. |
| `portcullis/public_admin_scripts` | filter | `wp-admin/` scripts that stay reachable anonymously. `admin-ajax.php` and `admin-post.php` by default. |
| `portcullis/render_theme_404` | filter | Set to `false` to answer blocked requests with a minimal document instead of the theme's 404 template. |
| `portcullis/lockout_message` | filter | Message shown to a locked-out visitor. Receives the message and the timestamp the lockout ends at. |
| `portcullis/locked_out` | action | Fires on every lockout with the `Lockout`, the `ClientIp` and the submitted login — for emails, a SIEM, a chat channel. |

Every adapter can be replaced through `Portcullis::boot()`: `SlugProviderPort`,
`FeatureTogglePort`, `HookRegistrarPort`, `ThrottleSettingsPort` and
`AttemptStorePort`.

## Hook system

The package never calls `add_action()` or `apply_filters()` itself. Hook
registration sits behind `HookRegistrarPort`, with two implementations picked at
runtime:

- `PolloraHookRegistrar` — used when `Pollora\Support\Facades\Action` and
  `Filter` are loadable **and** the facade container is set, so that hooks take
  part in the framework's own lifecycle instead of bypassing it.
- `WordPressHookRegistrar` — the plain plugin API, used everywhere else.

Pollora is not a dependency: the adapter is only autoloaded once
`PolloraHookRegistrar::isAvailable()` says so, which keeps the package installable
on a bare Bedrock site and its PHP floor at 8.1.

The single exception is `Bootstrap`, which by definition runs before either
implementation could work.

## Upgrading from pollora/hidden-login

```bash
composer remove pollora/hidden-login
composer require pollora/portcullis
```

The two packages conflict, so they cannot be installed side by side.

Nothing else is required. The 1.x names keep working and can be migrated at your
own pace:

| 1.x | 2.x |
| --- | --- |
| `HIDDEN_LOGIN_SLUG` | `PORTCULLIS_LOGIN_SLUG` — the old name is still read as a fallback |
| `HIDDEN_LOGIN_ENABLED` | `PORTCULLIS_ENABLED` — same |
| `hidden_login/*` filters | `portcullis/*` — the old names are still applied, before the new ones |
| `wp hidden-login` | `wp portcullis` — the old name is an alias |
| `Pollora\HiddenLogin\HiddenLogin::boot()` | `Pollora\Portcullis\Portcullis::boot()` — the old class forwards, with a deprecation notice |
| `Pollora\HiddenLogin\Port\Out\*Port` | `Pollora\Portcullis\Port\Out\*Port` — the old names are aliases |

Two things change on upgrade:

- **Brute-force protection is on by default.** Set
  `PORTCULLIS_THROTTLE_ENABLED=false` to keep the 1.x behaviour, and set
  `PORTCULLIS_TRUSTED_PROXIES` first if the site sits behind a reverse proxy —
  otherwise every visitor shares the proxy's address.
- **`HookRegistrarPort` gained `doAction()`.** Only custom implementations of the
  port are affected.

## Architecture

Hexagonal, following the Pollora layout. The Domain and Application layers
contain no WordPress call at all, which is what makes the security-critical
decisions unit-testable without booting WordPress.

```
src/
├── Domain/              Value objects — LoginSlug, RequestPath, DefaultEndpoint, FeatureState,
│                        ClientIp, IpRange, Subject, ScopePolicy, ThrottlePolicy, Lockout…
├── Application/         Pure decisions — ResolveLoginSlug, MatchHiddenLoginRequest,
│                        GuardDefaultEndpoints, ClassifyStockAlias, RewriteLoginUrl,
│                        ResolveClientIp, DeriveSubjects, CheckLockout, RecordFailedAttempt,
│                        ClearAttempts
├── Port/Out/            SlugProvider, FeatureToggle, HookRegistrar, RequestContext,
│                        LoginScreenRenderer, NotFoundResponder, AttemptStore, Clock,
│                        ClientAddress, ThrottleSettings, LockoutNotifier
├── Adapter/In/          Routers, URL rewriter, admin notice, login throttle guard,
│                        generic login errors, purge scheduler, WP-CLI command
├── Adapter/Out/WordPress/  Plugin API, superglobals, wp-login.php, theme 404,
│                           environment settings, MySQL store and schema, error log
├── Adapter/Out/Pollora/    Hook registrar backed by the framework's facades
├── Bootstrap.php        Composer self-registration
└── Portcullis.php       Composition root
compat/                  pollora/hidden-login 1.x names
```

### Why two hooks

The order of `wp-settings.php` dictates the split:

- **`plugins_loaded`, priority 1** is the earliest point where
  `is_user_logged_in()` is usable (`pluggable.php` is loaded just above), and it
  is still early enough to rewrite the request environment before any plugin
  reads it. It also runs *before* `wp-admin/admin.php` reaches
  `auth_redirect()`, which would otherwise redirect anonymous visitors straight
  to the secret URL.
- **`wp_loaded`, last priority** is where the response is produced. Rendering
  needs `$wp`, `$wp_query` and `$wp_rewrite`, which only exist after
  `plugins_loaded`, and running *last* is what reproduces the native ordering:
  `wp-blog-header.php` calls `wp()` once `wp_loaded` has fully completed, and
  `wp-login.php` renders once `wp-load.php` has returned. Rendering earlier
  skips whatever registers on `wp_loaded` — on a Sage theme, Acorn would not
  have bound its `template_include` filter yet and the 404 template fatals
  instead of rendering.

### Three details that are load-bearing

- **The canonical request path has no trailing slash.** The password reset
  screen scopes its `wp-resetpass-*` cookie on the current request path, while
  the form it renders posts to the rewritten URL. A slash on one side only makes
  the cookie invisible to the POST, and the reset fails with an "expired link"
  error that is close to impossible to diagnose.
- **`wp_redirect` is filtered, not just `site_url`.** `wp-login.php` redirects to
  *relative* locations in several branches —
  `wp_safe_redirect( 'wp-login.php?checkemail=confirm' )` after a lost password
  request is the one users hit first. Left alone, the browser resolves it
  against the secret slug and lands on `/<slug>/wp-login.php`.
- **The blocked request keeps its own path.** Rendering the 404 against a decoy
  path would be simpler, but WordPress echoes the current URL into the page —
  a login link's `redirect_to`, for instance — so the decoy would show up in the
  markup and hand a scanner exactly the signal this package exists to withhold.

### Known caveat

Blocked `wp-admin/` requests are rendered with `WP_ADMIN` already defined — the
admin bootstrap sets it before WordPress is even loaded, so it cannot be undone.
`is_admin()` is therefore `true` while the theme's 404 template renders, with two
consequences:

- The admin bar is unhooked explicitly. `is_admin_bar_showing()` returns `true`
  *unconditionally* under `is_admin()` — it never reaches the `show_admin_bar`
  filter — and the bar then fatals on a `null` `get_current_screen()`, because
  the administration bootstrap is interrupted long before `set_current_screen()`
  runs. Unhooking the `default-filters.php` callbacks is the only lever that
  works.
- Plugins that skip front-end output under `is_admin()` do not contribute. With
  Yoast SEO, for instance, the `wp-admin/` 404 has a plain `<head>` where a
  front-end 404 carries the SEO meta. The page renders correctly and returns
  404, but it is not byte-identical to a front-end 404 the way the
  `wp-login.php` one is.

If a theme or plugin misbehaves in that context, opt out with
`portcullis/render_theme_404`.

## Quality

```bash
composer test          # pest (unit) + phpstan + pint --test
composer test:unit
composer phpstan
composer lint
```

The integration suite runs the storage contract against the MySQL adapter on a
real WordPress installation, on a table of its own:

```bash
PORTCULLIS_WP_LOAD=/path/to/wp-load.php vendor/bin/pest --testsuite=Integration
```
