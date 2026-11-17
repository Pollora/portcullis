<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\In\WordPress\AccountIdentifier;
use Pollora\Portcullis\Adapter\In\WordPress\LoginThrottleGuard;
use Pollora\Portcullis\Adapter\Out\WordPress\SuperglobalClientAddress;
use Pollora\Portcullis\Application\Service\CheckLockout;
use Pollora\Portcullis\Application\Service\ClearAttempts;
use Pollora\Portcullis\Application\Service\DeriveSubjects;
use Pollora\Portcullis\Application\Service\RecordFailedAttempt;
use Pollora\Portcullis\Application\Service\ResolveClientIp;
use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\IpRange;
use Pollora\Portcullis\Domain\Model\Lockout;
use Pollora\Portcullis\Domain\Model\ScopePolicy;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;
use Pollora\Portcullis\Domain\Model\ThrottleSettings;
use Pollora\Portcullis\Port\Out\AttemptStorePort;
use Pollora\Portcullis\Port\Out\LockoutNotifierPort;
use Pollora\Portcullis\Tests\Support\ExplodingAttemptStore;
use Pollora\Portcullis\Tests\Support\FrozenClock;
use Pollora\Portcullis\Tests\Support\InMemoryAttemptStore;
use Pollora\Portcullis\Tests\Support\RecordingHooks;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

const THROTTLE_SECRET = 'a-secret-long-enough-for-the-subject-derivation';

/**
 * Wires the guard against in-memory ports and registers it on the fake plugin API.
 *
 * @param  list<string>  $allowlist
 * @param  list<string>  $trustedProxies
 * @return array{guard: LoginThrottleGuard, store: InMemoryAttemptStore, clock: FrozenClock, hooks: RecordingHooks, notified: ArrayObject<int, array{Lockout, ClientIp, string}>}
 */
function throttleGuard(array $allowlist = [], array $trustedProxies = [], ?AttemptStorePort $failing = null, bool $accountScope = true): array
{
    $clock = new FrozenClock;
    $store = new InMemoryAttemptStore;
    $backend = $failing ?? $store;
    $hooks = new RecordingHooks;
    $policy = new ThrottlePolicy(
        3600,
        new ScopePolicy(3, 600, 2, 7200),
        $accountScope ? new ScopePolicy(5, 900, 2, 7200) : null,
    );
    $settings = new ThrottleSettings(
        $policy,
        IpRange::listFromString(implode(',', $trustedProxies)),
        IpRange::listFromString(implode(',', $allowlist)),
        THROTTLE_SECRET,
        true,
    );

    $notified = new ArrayObject;
    $notifier = new class($notified) implements LockoutNotifierPort
    {
        public function __construct(private readonly ArrayObject $log) {}

        public function lockedOut(Lockout $lockout, ClientIp $ip, string $login): void
        {
            $this->log[] = [$lockout, $ip, $login];
        }
    };

    // The guard keeps per-request state (the resolved address, a refusal), so
    // each simulated request gets a guard of its own on the shared store.
    $GLOBALS['portcullis_throttle_request'] = static function () use ($settings, $backend, $policy, $clock, $notifier, $hooks): LoginThrottleGuard {
        Wp::$hooks = [];

        $guard = new LoginThrottleGuard(
            $settings,
            new CheckLockout($backend, $clock),
            new RecordFailedAttempt($backend, $policy, $clock),
            new ClearAttempts($backend),
            new DeriveSubjects(THROTTLE_SECRET),
            new ResolveClientIp,
            new SuperglobalClientAddress,
            new AccountIdentifier,
            $notifier,
            $clock,
            $hooks,
        );
        $guard->register();

        return $guard;
    };

    return ['guard' => newRequest(), 'store' => $store, 'clock' => $clock, 'hooks' => $hooks, 'notified' => $notified];
}

/**
 * Starts a new simulated request: a fresh guard on the same store and clock.
 */
function newRequest(): LoginThrottleGuard
{
    return $GLOBALS['portcullis_throttle_request']();
}

/**
 * Runs a login through the filter chain, the way wp_authenticate() does.
 */
function attemptLogin(string $login, mixed $user = null, string $password = 'secret'): mixed
{
    $user ??= new WP_Error('incorrect_password', 'wrong');

    return apply_filters('authenticate', $user, $login, $password);
}

function failFrom(string $ip, string $login, int $times): void
{
    $_SERVER['REMOTE_ADDR'] = $ip;

    for ($i = 0; $i < $times; $i++) {
        newRequest();
        attemptLogin($login);
    }

    newRequest();
}

beforeEach(function (): void {
    $this->server = $_SERVER;
    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['PHP_AUTH_USER']);
});

afterEach(function (): void {
    $_SERVER = $this->server;
});

it('registers on every hook a password can be tried through', function (): void {
    throttleGuard();

    expect(count(Wp::callbacks('authenticate')))->toBe(2)
        ->and(count(Wp::callbacks('wp_login_failed')))->toBe(1)
        ->and(count(Wp::callbacks('wp_login')))->toBe(1)
        ->and(count(Wp::callbacks('determine_current_user')))->toBe(1)
        ->and(count(Wp::callbacks('application_password_failed_authentication')))->toBe(1)
        ->and(count(Wp::callbacks('shake_error_codes')))->toBe(1);
});

it('lets failed attempts through untouched below the threshold', function (): void {
    throttleGuard();

    $result = attemptLogin('admin');

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_codes())->toBe(['incorrect_password']);
});

it('locks the address out at the threshold and says so', function (): void {
    ['notified' => $notified] = throttleGuard();

    failFrom('198.51.100.10', 'admin', 2);
    $third = attemptLogin('admin');

    expect($third)->toBeInstanceOf(WP_Error::class)
        ->and($third->get_error_codes())->toBe([LoginThrottleGuard::ERROR_CODE])
        ->and($third->get_error_message())->toContain('Too many failed login attempts')
        ->and($notified)->toHaveCount(1)
        ->and($notified[0][2])->toBe('admin');
});

it('refuses a locked-out address even with the right password', function (): void {
    ['hooks' => $hooks] = throttleGuard();

    failFrom('198.51.100.10', 'admin', 3);

    $correct = attemptLogin('admin', new WP_User(1, 'admin', 'admin'), 'the-right-password');

    expect($correct)->toBeInstanceOf(WP_Error::class)
        ->and($correct->get_error_codes())->toBe([LoginThrottleGuard::ERROR_CODE]);
    // The password is never even checked: the core handlers are taken off.
    expect($hooks->removedCallback('authenticate', 'wp_authenticate_username_password'))->toBeTrue()
        ->and($hooks->removedCallback('authenticate', 'wp_authenticate_email_password'))->toBeTrue()
        ->and($hooks->removedCallback('authenticate', 'wp_authenticate_application_password'))->toBeTrue()
        ->and($hooks->removedCallback('authenticate', 'wp_authenticate_cookie'))->toBeTrue();
});

it('refuses a third-party handler that authenticated a locked-out address', function (): void {
    throttleGuard();
    failFrom('198.51.100.10', 'admin', 3);

    // A handler registered after the core ones (an SSO plugin, say) still
    // returns a user: the refusal must hold.
    $result = apply_filters('authenticate', new WP_User(7, 'sso', 'sso'), 'sso', 'token');

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe(LoginThrottleGuard::ERROR_CODE);
});

it('does not touch a successful login of an address that is not locked out', function (): void {
    throttleGuard();
    $user = new WP_User(1, 'admin', 'admin');

    expect(attemptLogin('admin', $user, 'right'))->toBe($user);
});

it('does not count or look up an empty form', function (): void {
    $store = new ExplodingAttemptStore;
    throttleGuard(failing: $store);

    $error = new WP_Error('empty_username', 'empty');

    expect(apply_filters('authenticate', $error, '', ''))->toBe($error)
        ->and($store->calls)->toBe(0);
});

it('counts a failure with an empty login but a password', function (): void {
    throttleGuard();

    failFrom('198.51.100.10', '', 3);

    expect(apply_filters('authenticate', null, '', 'pw'))->toBeInstanceOf(WP_Error::class);
});

it('does not count errors that are not credential failures', function (): void {
    throttleGuard();
    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    newRequest();

    for ($i = 0; $i < 10; $i++) {
        $result = attemptLogin('admin', new WP_Error('empty_password', 'empty'));
        expect($result->get_error_codes())->toBe(['empty_password']);
    }
});

it('counts each credential-failure code as a failure', function (string $code): void {
    throttleGuard();

    for ($i = 0; $i < 3; $i++) {
        $result = attemptLogin('admin', new WP_Error($code, 'nope'));
    }

    expect($result->get_error_code())->toBe(LoginThrottleGuard::ERROR_CODE);
})->with(['authentication_failed', 'incorrect_password', 'invalid_email', 'invalid_username']);

it('locks an account out across addresses once the account threshold is reached', function (): void {
    throttleGuard();

    // Five addresses, one failure each: none reaches the address threshold (3),
    // all of them reach the account threshold (5).
    foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4'] as $ip) {
        failFrom($ip, 'admin', 1);
    }
    $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
    newRequest();
    $fifth = attemptLogin('admin');

    expect($fifth->get_error_code())->toBe(LoginThrottleGuard::ERROR_CODE);

    // A fresh address is now refused for that account...
    $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
    newRequest();
    $refused = attemptLogin('admin', new WP_User(1, 'admin', 'admin'));

    expect($refused->get_error_code())->toBe(LoginThrottleGuard::ERROR_CODE);

    // ... and not for another account.
    newRequest();
    $other = attemptLogin('someone-else', new WP_User(2, 'x', 'x'));

    expect($other)->toBeInstanceOf(WP_User::class);
});

it('counts per address only when the account scope is not counted', function (): void {
    throttleGuard(accountScope: false);

    foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4', '203.0.113.5', '203.0.113.6'] as $ip) {
        failFrom($ip, 'admin', 1);
    }

    $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
    newRequest();

    expect(attemptLogin('admin', new WP_User(1, 'admin', 'admin')))->toBeInstanceOf(WP_User::class);
});

it('counts the login and the email address of one user as the same account', function (): void {
    // The login and the address the user owns are one account once resolved.
    $user = new WP_User(42, 'admin', 'admin');
    Wp::$usersByLogin['admin'] = $user;
    Wp::$usersByEmail['admin@example.test'] = $user;
    throttleGuard();

    foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4'] as $ip) {
        failFrom($ip, 'admin', 1);
    }
    $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
    newRequest();
    $byEmail = attemptLogin('admin@example.test');

    expect($byEmail->get_error_code())->toBe(LoginThrottleGuard::ERROR_CODE);
});

it('never counts nor locks an allowlisted address', function (): void {
    ['store' => $store] = throttleGuard(allowlist: ['203.0.113.0/24']);

    failFrom('203.0.113.50', 'admin', 20);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
    newRequest();

    expect(attemptLogin('admin', new WP_User(1, 'admin', 'admin')))->toBeInstanceOf(WP_User::class)
        ->and($store->count())->toBe(0);
});

it('still locks an address next to the allowlisted range', function (): void {
    throttleGuard(allowlist: ['203.0.113.0/24']);

    failFrom('203.0.114.50', 'admin', 3);

    expect(attemptLogin('admin', new WP_User(1, 'admin', 'admin')))->toBeInstanceOf(WP_Error::class);
});

it('ignores a spoofed forwarded header when the peer is not a trusted proxy', function (): void {
    throttleGuard();

    // The attacker rotates X-Forwarded-For to dodge the counter; the address
    // that counts is REMOTE_ADDR.
    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    newRequest();
    foreach (['1.1.1.1', '2.2.2.2', '3.3.3.3'] as $spoofed) {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $spoofed;
        $last = attemptLogin('admin');
    }

    expect($last->get_error_code())->toBe(LoginThrottleGuard::ERROR_CODE);
});

it('honours the forwarded header when the peer is a trusted proxy', function (): void {
    throttleGuard(trustedProxies: ['10.0.0.1']);

    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    newRequest();
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
    newRequest();
    for ($i = 0; $i < 3; $i++) {
        $last = attemptLogin('admin');
    }

    expect($last->get_error_code())->toBe(LoginThrottleGuard::ERROR_CODE);

    // A different client behind the same proxy is not collateral damage.
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.200';
    newRequest();

    expect(attemptLogin('someone', new WP_User(3, 'x', 'x')))->toBeInstanceOf(WP_User::class);
});

it('leaves the request alone when no client address can be resolved', function (): void {
    ['store' => $store] = throttleGuard();
    $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
    newRequest();

    for ($i = 0; $i < 5; $i++) {
        $result = attemptLogin('admin');
    }

    expect($result->get_error_code())->toBe('incorrect_password')
        ->and($store->count())->toBe(0);
});

it('lets the lockout end once its duration has passed', function (): void {
    ['clock' => $clock] = throttleGuard();
    failFrom('198.51.100.10', 'admin', 3);

    expect(attemptLogin('admin', new WP_User(1, 'admin', 'admin')))->toBeInstanceOf(WP_Error::class);

    $clock->advance(601);
    newRequest();

    expect(attemptLogin('admin', new WP_User(1, 'admin', 'admin')))->toBeInstanceOf(WP_User::class);
});

it('keeps authenticating when the store is down, and reports it once', function (): void {
    $log = tempnam(sys_get_temp_dir(), 'portcullis-log');
    $previous = ini_set('error_log', $log);
    $store = new ExplodingAttemptStore;
    throttleGuard(failing: $store);

    $first = attemptLogin('admin');
    $second = attemptLogin('admin');

    $lines = array_filter(explode("\n", (string) file_get_contents($log)));
    ini_set('error_log', (string) $previous);
    unlink($log);

    // Failing open is a deliberate trade-off: a broken counter must not lock
    // everybody out of the site. WordPress' own error is what comes back.
    expect($first->get_error_code())->toBe('incorrect_password')
        ->and($second->get_error_code())->toBe('incorrect_password')
        ->and($lines)->toHaveCount(1)
        ->and(array_values($lines)[0])->toContain('[portcullis] brute-force protection skipped for this request: [portcullis] connection refused');
});

it('does not let a failed login count twice through authenticate and wp_login_failed', function (): void {
    ['store' => $store] = throttleGuard();

    $error = attemptLogin('admin');
    do_action('wp_login_failed', 'admin', $error);
    do_action('wp_login_failed', 'admin', $error);

    // Three records would have been a lockout; one failure was reported.
    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    newRequest();

    expect(attemptLogin('admin', new WP_User(1, 'a', 'a')))->toBeInstanceOf(WP_User::class)
        ->and($store->count())->toBe(2);
});

it('counts a failure reported only through wp_login_failed', function (): void {
    ['store' => $store] = throttleGuard();

    do_action('wp_login_failed', 'admin', new WP_Error('incorrect_password', 'x'));

    expect($store->count())->toBe(2);
});

it('counts a wp_login_failed without an error object', function (): void {
    ['store' => $store] = throttleGuard();

    do_action('wp_login_failed', 'admin');

    expect($store->count())->toBe(2);
});

it('ignores a wp_login_failed that is not a credential failure', function (): void {
    ['store' => $store] = throttleGuard();

    do_action('wp_login_failed', 'admin', new WP_Error('empty_password', 'x'));

    expect($store->count())->toBe(0);
});

it('does not record a failure for a request it already refused', function (): void {
    ['store' => $store] = throttleGuard();
    failFrom('198.51.100.10', 'admin', 3);
    $before = $store->count();

    attemptLogin('admin');
    do_action('wp_login_failed', 'admin', new WP_Error('incorrect_password', 'x'));
    do_action('application_password_failed_authentication', new WP_Error('incorrect_password', 'x'));

    expect($store->count())->toBe($before);
});

it('clears the account counters on a successful login, not those of the address', function (): void {
    ['store' => $store] = throttleGuard();
    $user = new WP_User(9, 'jane', 'jane');
    Wp::$usersByLogin['jane'] = $user;

    failFrom('198.51.100.10', 'jane', 2);

    expect($store->count())->toBe(2);

    do_action('wp_login', 'jane', $user);

    expect($store->count())->toBe(1);
});

it('does not clear anything on wp_login without a user object', function (): void {
    ['store' => $store] = throttleGuard();
    failFrom('198.51.100.10', 'jane', 1);

    do_action('wp_login', 'jane');

    expect($store->count())->toBe(2);
});

it('survives a store failure while clearing', function (): void {
    $log = tempnam(sys_get_temp_dir(), 'portcullis-log');
    $previous = ini_set('error_log', $log);
    throttleGuard(failing: new ExplodingAttemptStore);

    do_action('wp_login', 'jane', new WP_User(9, 'jane', 'jane'));

    $logged = (string) file_get_contents($log);
    ini_set('error_log', (string) $previous);
    unlink($log);

    expect($logged)->toContain('brute-force protection skipped');
});

it('refuses a locked-out address on Basic Auth and takes the application password check off', function (): void {
    ['hooks' => $hooks] = throttleGuard();
    failFrom('198.51.100.10', 'admin', 3);
    $_SERVER['PHP_AUTH_USER'] = 'admin';

    $userId = apply_filters('determine_current_user', false);

    expect($userId)->toBeFalse()
        ->and($hooks->removedCallback('determine_current_user', 'wp_validate_application_password'))->toBeTrue();
});

it('leaves Basic Auth alone when the address is not locked out', function (): void {
    ['hooks' => $hooks] = throttleGuard();
    $_SERVER['PHP_AUTH_USER'] = 'admin';

    apply_filters('determine_current_user', false);

    expect($hooks->removedCallback('determine_current_user', 'wp_validate_application_password'))->toBeFalse();
});

it('does not look anything up for an already authenticated request or without Basic Auth', function (): void {
    $store = new ExplodingAttemptStore;
    throttleGuard(failing: $store);

    expect(apply_filters('determine_current_user', 5))->toBe(5);

    $_SERVER['PHP_AUTH_USER'] = 'admin';

    expect(apply_filters('determine_current_user', 5))->toBe(5)
        ->and($store->calls)->toBe(0);

    $_SERVER['PHP_AUTH_USER'] = '';

    expect(apply_filters('determine_current_user', false))->toBeFalse()
        ->and($store->calls)->toBe(0);
});

it('counts a failed application password against the Basic Auth login', function (): void {
    ['store' => $store] = throttleGuard();
    $_SERVER['PHP_AUTH_USER'] = 'admin';

    do_action('application_password_failed_authentication', new WP_Error('incorrect_password', 'x'));

    expect($store->count())->toBe(2);
});

it('counts a failed application password without an error object, and without a login', function (): void {
    ['store' => $store] = throttleGuard();

    do_action('application_password_failed_authentication');

    // No Basic Auth login: only the address is counted, and the account scope
    // gets the empty identifier.
    expect($store->count())->toBe(1);
});

it('ignores an application password error that is not a credential failure', function (): void {
    ['store' => $store] = throttleGuard();

    do_action('application_password_failed_authentication', new WP_Error('application_passwords_disabled', 'x'));

    expect($store->count())->toBe(0);
});

it('makes the login form shake on a lockout', function (): void {
    throttleGuard();

    expect(apply_filters('shake_error_codes', ['empty_password']))->toBe(['empty_password', LoginThrottleGuard::ERROR_CODE])
        ->and(apply_filters('shake_error_codes', 'garbage'))->toBe([LoginThrottleGuard::ERROR_CODE]);
});

it('lets the host reword the lockout message, and falls back to an empty one on garbage', function (): void {
    throttleGuard();
    failFrom('198.51.100.10', 'admin', 3);

    add_filter('portcullis/lockout_message', static fn (string $message, int $until): string => 'Back later ('.$until.')', 10, 2);

    expect(attemptLogin('admin')->get_error_message())->toStartWith('Back later (');

    Wp::$hooks['portcullis/lockout_message'] = [];
    add_filter('portcullis/lockout_message', static fn (): array => ['not a string']);

    expect(attemptLogin('admin')->get_error_message())->toBe('');
});

/**
 * Failures recorded in the store, all counters together.
 */
function recordedFailures(InMemoryAttemptStore $store): int
{
    $rows = (new ReflectionProperty($store, 'rows'))->getValue($store);

    return array_sum(array_column($rows, 'failures'));
}

it('counts a login that is not a string as an empty one, which is no account', function (): void {
    ['store' => $store, 'notified' => $notified] = throttleGuard();

    apply_filters('authenticate', new WP_Error('incorrect_password', 'x'), null, 'secret');

    expect($store->count())->toBe(1)
        ->and($notified)->toHaveCount(0);

    newRequest();
    do_action('wp_login_failed', null, new WP_Error('incorrect_password', 'x'));

    expect($store->count())->toBe(1);
});

it('reports an empty login to the notifier when the lockout comes from a login that is not a string', function (): void {
    ['notified' => $notified] = throttleGuard();
    failFrom('198.51.100.10', 'u1', 2);

    apply_filters('authenticate', new WP_Error('incorrect_password', 'x'), null, 'secret');

    expect($notified)->toHaveCount(1)
        ->and($notified[0][2])->toBe('');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.11';
    failFrom('198.51.100.11', 'u2', 2);
    do_action('wp_login_failed', null, new WP_Error('incorrect_password', 'x'));

    expect($notified)->toHaveCount(2)
        ->and($notified[1][2])->toBe('');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.12';
    failFrom('198.51.100.12', 'u3', 2);
    do_action('application_password_failed_authentication', new WP_Error('incorrect_password', 'x'));

    expect($notified)->toHaveCount(3)
        ->and($notified[2][2])->toBe('');
});

it('does not count the same failure twice when it comes through authenticate and an application password', function (): void {
    ['store' => $store] = throttleGuard();
    $_SERVER['PHP_AUTH_USER'] = 'admin';

    attemptLogin('admin');
    $afterLogin = recordedFailures($store);
    do_action('application_password_failed_authentication', new WP_Error('incorrect_password', 'x'));

    expect($afterLogin)->toBe(2)
        ->and(recordedFailures($store))->toBe(2);
});

it('does not count an application password failure of a request it already refused', function (): void {
    ['store' => $store] = throttleGuard();
    failFrom('198.51.100.10', 'admin', 3);
    $_SERVER['PHP_AUTH_USER'] = 'admin';
    apply_filters('determine_current_user', false);
    $before = recordedFailures($store);

    do_action('application_password_failed_authentication', new WP_Error('incorrect_password', 'x'));
    do_action('wp_login_failed', 'admin', new WP_Error('incorrect_password', 'x'));

    expect(recordedFailures($store))->toBe($before);
});

it('removes the application password check at its own priority', function (): void {
    ['hooks' => $hooks] = throttleGuard();
    failFrom('198.51.100.10', 'admin', 3);
    $_SERVER['PHP_AUTH_USER'] = 'admin';

    apply_filters('determine_current_user', false);

    expect($hooks->removed)->toContain(['determine_current_user', 'wp_validate_application_password', 20]);
});

it('keeps a refusal a refusal, and only touches what a late handler authenticated', function (): void {
    throttleGuard();
    failFrom('198.51.100.10', 'admin', 3);
    $late = new WP_Error('sso_failed', 'no');
    add_filter('authenticate', static fn (mixed ...$arguments) => $late, 20, 3);

    // A late handler that does not authenticate anyone leaves its own error alone.
    $result = apply_filters('authenticate', null, 'admin', 'secret');

    expect($result)->toBe($late);
});

it('sets the 429 status of a lockout while headers can still be sent, and not after', function (): void {
    throttleGuard();
    failFrom('198.51.100.10', 'admin', 3);
    Wp::$statuses = [];

    $error = attemptLogin('admin');

    // Under a test runner that has already written to the terminal, headers can
    // no longer be sent: the status is then left alone, as in production.
    expect($error->get_error_code())->toBe(LoginThrottleGuard::ERROR_CODE)
        ->and(Wp::$statuses)->toBe(headers_sent() ? [] : [429]);
});

it('settles on the longest of the lockouts one failure triggers', function (): void {
    ['clock' => $clock] = throttleGuard();

    // Address A is locked once: its next lockout is a long one (2 hours).
    failFrom('198.51.100.10', 'bob', 3);
    $clock->advance(700);
    failFrom('198.51.100.10', 'carol', 2);
    // Address B brings the account of bob to four failures.
    failFrom('198.51.100.11', 'bob', 1);

    // One more failure of bob from A: A reaches 3 (long lockout, 2 hours) and
    // bob reaches 5 (15 minutes), in the same call, the address first.
    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    newRequest();
    $error = attemptLogin('bob');

    expect($error->get_error_code())->toBe(LoginThrottleGuard::ERROR_CODE)
        ->and($error->get_error_message())->toEndWith('Please try again in 2 hours.');
});

it('resolves the client address once per request', function (): void {
    throttleGuard();
    failFrom('198.51.100.10', 'admin', 3);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    $guard = newRequest();

    $first = $guard->refuseLockedOut(null, 'admin', 'secret');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.200';
    $second = $guard->refuseLockedOut(null, 'admin', 'secret');

    expect($first)->toBeInstanceOf(WP_Error::class)
        ->and($second)->toBeInstanceOf(WP_Error::class);
});

it('reports a store that fails while recording a failure, or clearing an account', function (string $hook, array $arguments): void {
    $log = tempnam(sys_get_temp_dir(), 'portcullis-log');
    $previous = ini_set('error_log', $log);
    throttleGuard(failing: new ExplodingAttemptStore);
    ini_set('error_log', $log);

    do_action($hook, ...$arguments);

    $logged = (string) file_get_contents($log);
    ini_set('error_log', (string) $previous);
    unlink($log);

    expect($logged)->toContain('[portcullis] brute-force protection skipped for this request: [portcullis] connection refused');
})->with([
    'a failure' => ['wp_login_failed', ['admin', new WP_Error('incorrect_password', 'x')]],
    'a successful login' => ['wp_login', ['admin', new WP_User(1, 'admin', 'admin')]],
]);

it('resolves an account by its login first, and by its email address only when that finds nobody', function (): void {
    $accounts = new AccountIdentifier;
    Wp::$usersByLogin['jane@example.test'] = new WP_User(5, 'jane@example.test', 'jane');
    Wp::$usersByEmail['jane@example.test'] = new WP_User(9, 'jane9', 'jane9');
    Wp::$usersByEmail['bob@example.test'] = new WP_User(7, 'bob', 'bob');

    expect($accounts->forLogin('jane@example.test'))->toBe('user:5')
        ->and($accounts->forLogin('bob@example.test'))->toBe('user:7')
        ->and($accounts->forLogin('ghost@example.test'))->toBe('login:ghost@example.test')
        ->and($accounts->forLogin('ghost'))->toBe('login:ghost');
});

it('turns a user that a late handler authenticated into a refusal, after refusing the attempt', function (): void {
    throttleGuard();
    failFrom('198.51.100.10', 'admin', 3);
    add_filter('authenticate', static fn (mixed ...$arguments) => new WP_User(7, 'sso', 'sso'), 20, 3);

    $result = apply_filters('authenticate', null, 'admin', 'secret');

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe(LoginThrottleGuard::ERROR_CODE);
});

it('reports a store that fails while looking a lockout up, even if nothing is recorded after', function (): void {
    $log = tempnam(sys_get_temp_dir(), 'portcullis-log');
    $previous = ini_set('error_log', $log);
    throttleGuard(failing: new ExplodingAttemptStore);
    ini_set('error_log', $log);
    $_SERVER['PHP_AUTH_USER'] = 'admin';

    apply_filters('determine_current_user', false);

    $logged = (string) file_get_contents($log);
    ini_set('error_log', (string) $previous);
    unlink($log);

    expect($logged)->toContain('[portcullis] brute-force protection skipped for this request: [portcullis] connection refused');
});
