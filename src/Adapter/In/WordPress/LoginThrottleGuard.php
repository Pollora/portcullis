<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\In\WordPress;

use Pollora\Portcullis\Application\Service\CheckLockout;
use Pollora\Portcullis\Application\Service\ClearAttempts;
use Pollora\Portcullis\Application\Service\DeriveSubjects;
use Pollora\Portcullis\Application\Service\RecordFailedAttempt;
use Pollora\Portcullis\Application\Service\ResolveClientIp;
use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\ThrottleSettings;
use Pollora\Portcullis\Port\Out\ClientAddressPort;
use Pollora\Portcullis\Port\Out\ClockPort;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;
use Pollora\Portcullis\Port\Out\LockoutNotifierPort;
use RuntimeException;
use WP_Error;
use WP_User;

/**
 * Plugs the brute-force protection into WordPress authentication.
 *
 * Every password check in WordPress — the login screen, XML-RPC, any plugin
 * calling `wp_signon()` — goes through the `authenticate` filter, so that is
 * where the guard sits, on both ends of it:
 *
 * - **first** (`PHP_INT_MIN`), it refuses a locked-out attempt and unhooks the
 *   core password checks. A refused attempt costs one primary key lookup and no
 *   password hashing, and a correct password guessed during a lockout gets the
 *   attacker nowhere.
 * - **last** (`PHP_INT_MAX`), it records the failure the filter chain produced,
 *   and turns the error into a lockout message when that failure is the one that
 *   triggered it.
 *
 * `wp_login_failed` is listened to as well, as a fallback: `wp_authenticate()` is
 * pluggable, and a replacement may not run the filter. A per-request flag keeps
 * the two paths from counting the same failure twice.
 *
 * Application passwords used over the REST API never reach `authenticate`: they
 * are checked from `determine_current_user`. The guard covers them through that
 * filter and `application_password_failed_authentication`.
 *
 * Everything is fail-open on storage errors. If the database cannot be reached,
 * WordPress cannot authenticate anyone anyway; if only the table is missing, an
 * error is logged and logins keep working rather than locking everyone out.
 */
final class LoginThrottleGuard
{
    /**
     * Error code of a refused attempt.
     */
    public const ERROR_CODE = 'portcullis_locked_out';

    /**
     * Error codes that denote a wrong credential, as opposed to an empty form or
     * an account-level refusal.
     *
     * @var list<string>
     */
    private const FAILURE_CODES = [
        'authentication_failed',
        'incorrect_password',
        'invalid_email',
        'invalid_username',
    ];

    /**
     * Core callbacks that check a password on `authenticate`, and their priority.
     *
     * @var array<string, int>
     */
    private const PASSWORD_CHECKS = [
        'wp_authenticate_username_password' => 20,
        'wp_authenticate_email_password' => 20,
        'wp_authenticate_application_password' => 20,
        'wp_authenticate_cookie' => 30,
    ];

    /**
     * Whether a failure has already been recorded for this request.
     */
    private bool $recorded = false;

    /**
     * End of the lockout this request was refused on, `null` when it was not.
     */
    private ?int $refusedUntil = null;

    /**
     * Whether {@see self::$clientIp} has been resolved yet.
     */
    private bool $clientIpResolved = false;

    /**
     * The client address, once resolved.
     */
    private ?ClientIp $clientIp = null;

    /**
     * Whether a storage error has already been logged for this request.
     */
    private bool $storageErrorReported = false;

    /**
     * @param  ThrottleSettings  $settings  Configuration.
     * @param  CheckLockout  $check  Tells whether subjects are locked out.
     * @param  RecordFailedAttempt  $record  Counts failures.
     * @param  ClearAttempts  $clear  Forgets failures.
     * @param  DeriveSubjects  $subjects  Derives storage keys.
     * @param  ResolveClientIp  $resolver  Works out the client address.
     * @param  ClientAddressPort  $address  Raw client address facts.
     * @param  AccountIdentifier  $accounts  Resolves logins to stable identifiers.
     * @param  LockoutNotifierPort  $notifier  Reports lockouts.
     * @param  ClockPort  $clock  Current time.
     * @param  HookRegistrarPort  $hooks  Hook system of the host.
     */
    public function __construct(
        private readonly ThrottleSettings $settings,
        private readonly CheckLockout $check,
        private readonly RecordFailedAttempt $record,
        private readonly ClearAttempts $clear,
        private readonly DeriveSubjects $subjects,
        private readonly ResolveClientIp $resolver,
        private readonly ClientAddressPort $address,
        private readonly AccountIdentifier $accounts,
        private readonly LockoutNotifierPort $notifier,
        private readonly ClockPort $clock,
        private readonly HookRegistrarPort $hooks,
    ) {}

    /**
     * Registers the authentication hooks.
     */
    public function register(): void
    {
        $this->hooks->addFilter('authenticate', [$this, 'refuseLockedOut'], PHP_INT_MIN, 3);
        $this->hooks->addFilter('authenticate', [$this, 'recordOutcome'], PHP_INT_MAX, 3);
        $this->hooks->addAction('wp_login_failed', [$this, 'recordLoginFailed'], 10, 2);
        $this->hooks->addAction('wp_login', [$this, 'clearAccount'], 1, 2);
        $this->hooks->addFilter('determine_current_user', [$this, 'refuseLockedOutApplicationPassword'], 19, 1);
        $this->hooks->addAction('application_password_failed_authentication', [$this, 'recordApplicationPasswordFailure'], 10, 1);
        $this->hooks->addFilter('shake_error_codes', [$this, 'shakeOnLockout'], 10, 1);
    }

    /**
     * Refuses the attempt when its address or account is locked out.
     *
     * @internal Hooked on `authenticate` first; not part of the public API.
     *
     * Arguments are typed `mixed` because any plugin on the filter may have
     * replaced them; they are checked before use.
     *
     * @param  mixed  $user  Result so far: a WP_User, a WP_Error or null.
     * @param  mixed  $username  Submitted login.
     * @param  mixed  $password  Submitted password.
     */
    public function refuseLockedOut(mixed $user, mixed $username, mixed $password): mixed
    {
        $login = is_string($username) ? $username : '';

        if ($login === '' && (! is_string($password) || $password === '')) {
            // An empty form: WordPress answers it with its own "empty field"
            // errors, which are neither counted nor worth a lookup.
            return $user;
        }

        $lockedUntil = $this->lockedUntil($login);

        if ($lockedUntil === null) {
            return $user;
        }

        $this->refusedUntil = $lockedUntil;

        foreach (self::PASSWORD_CHECKS as $callback => $priority) {
            $this->hooks->removeAction('authenticate', $callback, $priority);
        }

        return $this->lockoutError($lockedUntil);
    }

    /**
     * Records the failure the filter chain produced.
     *
     * @internal Hooked on `authenticate` last; not part of the public API.
     *
     * @param  mixed  $user  Final result of the chain: a WP_User, a WP_Error or null.
     * @param  mixed  $username  Submitted login.
     * @param  mixed  $password  Submitted password.
     */
    public function recordOutcome(mixed $user, mixed $username, mixed $password): mixed
    {
        if ($this->refusedUntil !== null) {
            // A third-party handler registered after the core ones may still have
            // authenticated the user. A refusal must stay a refusal.
            return $user instanceof WP_User ? $this->lockoutError($this->refusedUntil) : $user;
        }

        if (! $user instanceof WP_Error || ! $this->isCredentialFailure($user)) {
            return $user;
        }

        $lockedUntil = $this->recordFailure(is_string($username) ? $username : '');

        return $lockedUntil === null ? $user : $this->lockoutError($lockedUntil);
    }

    /**
     * Records a failure reported by a pluggable `wp_authenticate()` that bypassed the filter.
     *
     * @internal Hooked on `wp_login_failed`; not part of the public API.
     *
     * @param  mixed  $username  Submitted login.
     * @param  mixed  $error  Reason of the failure, a WP_Error since WordPress 5.4.
     */
    public function recordLoginFailed(mixed $username, mixed $error = null): void
    {
        if ($this->recorded || $this->refusedUntil !== null) {
            return;
        }

        if ($error instanceof WP_Error && ! $this->isCredentialFailure($error)) {
            return;
        }

        $this->recordFailure(is_string($username) ? $username : '');
    }

    /**
     * Forgets the failures of an account that has just logged in.
     *
     * The address counter is left alone on purpose. Clearing it would let
     * anyone holding an account — on a site with open registration, anyone —
     * reset their own address between two bursts of guesses on someone else's
     * account, and never be locked out.
     *
     * @internal Hooked on `wp_login`; not part of the public API.
     *
     * @param  mixed  $userLogin  Login of the user.
     * @param  mixed  $user  The user.
     */
    public function clearAccount(mixed $userLogin, mixed $user = null): void
    {
        if (! $user instanceof WP_User) {
            return;
        }

        try {
            $this->clear->clear([$this->subjects->forAccount($this->accounts->forUser($user))]);
        } catch (RuntimeException $exception) {
            $this->reportStorageError($exception);
        }
    }

    /**
     * Keeps a locked-out client from authenticating over the REST API with an application password.
     *
     * @internal Hooked on `determine_current_user` just before core checks application passwords.
     *
     * @param  mixed  $userId  User determined so far, an ID or false.
     */
    public function refuseLockedOutApplicationPassword(mixed $userId): mixed
    {
        $login = $this->basicAuthLogin();

        if ($userId || $login === null) {
            return $userId;
        }

        $lockedUntil = $this->lockedUntil($login);

        if ($lockedUntil !== null) {
            $this->refusedUntil = $lockedUntil;
            $this->hooks->removeAction('determine_current_user', 'wp_validate_application_password', 20);
        }

        return $userId;
    }

    /**
     * Records a failed application password.
     *
     * @internal Hooked on `application_password_failed_authentication`; not part of the public API.
     *
     * @param  mixed  $error  Reason of the failure.
     */
    public function recordApplicationPasswordFailure(mixed $error = null): void
    {
        if ($this->recorded || $this->refusedUntil !== null) {
            return;
        }

        if ($error instanceof WP_Error && ! $this->isCredentialFailure($error)) {
            return;
        }

        $this->recordFailure($this->basicAuthLogin() ?? '');
    }

    /**
     * Makes the login form shake on a lockout, as it does on a wrong password.
     *
     * @internal Hooked on `shake_error_codes`; not part of the public API.
     *
     * @param  mixed  $codes  Codes that shake the form.
     * @return array<int, mixed>
     */
    public function shakeOnLockout(mixed $codes): array
    {
        $codes = is_array($codes) ? $codes : [];
        $codes[] = self::ERROR_CODE;

        return $codes;
    }

    /**
     * End of the longest lockout applying to the attempt, `null` when none does.
     *
     * @param  string  $login  Submitted login.
     */
    private function lockedUntil(string $login): ?int
    {
        $ip = $this->clientIp();

        if ($ip === null || $this->settings->isAllowlisted($ip)) {
            return null;
        }

        try {
            return $this->check->lockedUntil($this->subjects->forAttempt($ip, $this->accounts->forLogin($login)));
        } catch (RuntimeException $exception) {
            $this->reportStorageError($exception);

            return null;
        }
    }

    /**
     * Counts a failure, reports the lockouts it triggered and returns the longest one.
     *
     * @param  string  $login  Submitted login.
     * @return int|null End of the lockout this failure triggered, `null` when it triggered none.
     */
    private function recordFailure(string $login): ?int
    {
        $this->recorded = true;

        $ip = $this->clientIp();

        if ($ip === null || $this->settings->isAllowlisted($ip)) {
            return null;
        }

        try {
            $lockouts = $this->record->record($this->subjects->forAttempt($ip, $this->accounts->forLogin($login)));
        } catch (RuntimeException $exception) {
            $this->reportStorageError($exception);

            return null;
        }

        $lockedUntil = null;

        foreach ($lockouts as $lockout) {
            $this->notifier->lockedOut($lockout, $ip, $login);
            $lockedUntil = max($lockedUntil ?? 0, $lockout->lockedUntil());
        }

        return $lockedUntil;
    }

    /**
     * The error returned for a locked-out attempt.
     *
     * The message says how long to wait, which is what a legitimate user needs;
     * it discloses nothing an attacker could not measure by retrying. The status
     * is set to 429 so that monitoring and upstream proxies see the refusal.
     *
     * @param  int  $lockedUntil  End of the lockout.
     */
    private function lockoutError(int $lockedUntil): WP_Error
    {
        if (! headers_sent()) {
            status_header(429);
        }

        $message = sprintf(
            /* translators: %s: human-readable duration, such as "20 minutes" */
            __('<strong>Error:</strong> Too many failed login attempts. Please try again in %s.', 'portcullis'),
            human_time_diff($this->clock->now(), $lockedUntil),
        );

        /**
         * Filters the message shown to a locked-out visitor.
         *
         * @param  string  $message  Message, HTML allowed as in any login error.
         * @param  int  $lockedUntil  Timestamp the lockout ends at.
         */
        $message = $this->hooks->applyFilters('portcullis/lockout_message', $message, $lockedUntil);

        return new WP_Error(self::ERROR_CODE, is_string($message) ? $message : '');
    }

    /**
     * Whether the error denotes a wrong credential.
     *
     * @param  WP_Error  $error  Error to inspect.
     */
    private function isCredentialFailure(WP_Error $error): bool
    {
        return array_intersect($error->get_error_codes(), self::FAILURE_CODES) !== [];
    }

    /**
     * The client address, resolved once per request.
     */
    private function clientIp(): ?ClientIp
    {
        if (! $this->clientIpResolved) {
            $this->clientIp = $this->resolver->resolve(
                $this->address->remoteAddress(),
                $this->address->forwardedFor(),
                $this->settings->trustedProxies(),
            );
            $this->clientIpResolved = true;
        }

        return $this->clientIp;
    }

    /**
     * The login sent through HTTP Basic authentication, `null` when there is none.
     */
    private function basicAuthLogin(): ?string
    {
        $login = $_SERVER['PHP_AUTH_USER'] ?? null;

        return is_string($login) && $login !== '' ? $login : null;
    }

    /**
     * Logs a storage error, once per request.
     *
     * @param  RuntimeException  $exception  The error.
     */
    private function reportStorageError(RuntimeException $exception): void
    {
        if ($this->storageErrorReported) {
            return;
        }

        $this->storageErrorReported = true;

        error_log('[portcullis] brute-force protection skipped for this request: '.$exception->getMessage());
    }
}
