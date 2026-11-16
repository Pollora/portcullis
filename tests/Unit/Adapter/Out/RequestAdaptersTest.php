<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\ErrorLogLockoutNotifier;
use Pollora\Portcullis\Adapter\Out\WordPress\SuperglobalClientAddress;
use Pollora\Portcullis\Adapter\Out\WordPress\SuperglobalRequestContext;
use Pollora\Portcullis\Adapter\Out\WordPress\SystemClock;
use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\DefaultEndpoint;
use Pollora\Portcullis\Domain\Model\Lockout;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

beforeEach(function (): void {
    $this->saved = $_SERVER;
    $this->savedRequest = $_REQUEST;
    $this->context = new SuperglobalRequestContext(new WordPressHookRegistrar);
});

afterEach(function (): void {
    $_SERVER = $this->saved;
    $_REQUEST = $this->savedRequest;
});

it('reads the request URI, and an empty string when the server gives none', function (): void {
    $_SERVER['REQUEST_URI'] = '/wp-admin/options.php?x=1';

    expect($this->context->requestUri())->toBe('/wp-admin/options.php?x=1');

    unset($_SERVER['REQUEST_URI']);

    expect($this->context->requestUri())->toBe('');
});

it('derives the home path without slashes, empty for a root install', function (): void {
    Wp::$home = 'https://example.test/blog/';

    expect($this->context->homePath())->toBe('blog');

    Wp::$home = 'https://example.test';

    expect($this->context->homePath())->toBe('');
});

it('recognises wp-login.php as the login endpoint, whatever the admin state', function (): void {
    $GLOBALS['pagenow'] = 'wp-login.php';

    expect($this->context->defaultEndpoint())->toBe(DefaultEndpoint::Login);
});

it('recognises wp-admin as the admin endpoint', function (): void {
    $GLOBALS['pagenow'] = 'index.php';
    Wp::$admin = true;

    expect($this->context->defaultEndpoint())->toBe(DefaultEndpoint::Admin);
});

it('knows no default endpoint on the front end', function (): void {
    $GLOBALS['pagenow'] = 'index.php';

    expect($this->context->defaultEndpoint())->toBeNull();
});

it('keeps the public admin scripts reachable', function (string $script): void {
    $GLOBALS['pagenow'] = $script;
    Wp::$admin = true;

    expect($this->context->defaultEndpoint())->toBeNull();
})->with(['admin-ajax.php', 'admin-post.php']);

it('does not open the public scripts to any other admin script', function (): void {
    $GLOBALS['pagenow'] = 'admin-ajax.php.php';
    Wp::$admin = true;

    expect($this->context->defaultEndpoint())->toBe(DefaultEndpoint::Admin);
});

it('lets the host open an additional admin script through the filter', function (): void {
    $GLOBALS['pagenow'] = 'payment-callback.php';
    Wp::$admin = true;
    add_filter('portcullis/public_admin_scripts', static fn (array $scripts): array => [...$scripts, 'payment-callback.php']);

    expect($this->context->defaultEndpoint())->toBeNull();
});

it('still honours the 1.x filter name for public admin scripts', function (): void {
    $GLOBALS['pagenow'] = 'legacy.php';
    Wp::$admin = true;
    add_filter('hidden_login/public_admin_scripts', static fn (array $scripts): array => [...$scripts, 'legacy.php']);

    expect($this->context->defaultEndpoint())->toBeNull();
});

it('falls back to the built-in public scripts when a filter returns garbage, never to an open admin', function (): void {
    $GLOBALS['pagenow'] = 'secret-admin.php';
    Wp::$admin = true;
    add_filter('portcullis/public_admin_scripts', static fn (): string => 'not-an-array');

    expect($this->context->defaultEndpoint())->toBe(DefaultEndpoint::Admin);

    $GLOBALS['pagenow'] = 'admin-ajax.php';

    expect($this->context->defaultEndpoint())->toBeNull();
});

it('ignores non-string entries a filter adds to the public scripts', function (): void {
    $GLOBALS['pagenow'] = '1';
    Wp::$admin = true;
    add_filter('portcullis/public_admin_scripts', static fn (): array => [1, null, 'admin-ajax.php']);

    expect($this->context->defaultEndpoint())->toBe(DefaultEndpoint::Admin);
});

it('treats a non-string pagenow as no page at all', function (): void {
    $GLOBALS['pagenow'] = 42;
    Wp::$admin = false;

    expect($this->context->defaultEndpoint())->toBeNull();
});

it('reads the requested action, defaulting to login', function (): void {
    $_REQUEST = ['action' => 'lostpassword'];

    expect($this->context->requestedAction())->toBe('lostpassword');

    $_REQUEST = ['action' => ''];

    expect($this->context->requestedAction())->toBe('login');

    $_REQUEST = ['action' => ['array']];

    expect($this->context->requestedAction())->toBe('login');

    $_REQUEST = [];

    expect($this->context->requestedAction())->toBe('login');
});

it('reflects the logged-in state of WordPress', function (): void {
    expect($this->context->isUserLoggedIn())->toBeFalse();

    Wp::$loggedIn = true;

    expect($this->context->isUserLoggedIn())->toBeTrue();
});

it('detects a cron request as a non-HTTP context, so that it is never blocked', function (): void {
    expect($this->context->isNonHttpContext())->toBeFalse();

    Wp::$doingCron = true;

    expect($this->context->isNonHttpContext())->toBeTrue();
});

it('reads the remote address and the forwarded header from the server variables', function (): void {
    $address = new SuperglobalClientAddress;

    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.1';

    expect($address->remoteAddress())->toBe('198.51.100.7')
        ->and($address->forwardedFor())->toBe('203.0.113.9, 10.0.0.1');
});

it('reports empty address facts when the server variables are missing or empty', function (): void {
    $address = new SuperglobalClientAddress;

    unset($_SERVER['REMOTE_ADDR']);
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '';

    expect($address->remoteAddress())->toBe('')
        ->and($address->forwardedFor())->toBeNull();

    unset($_SERVER['HTTP_X_FORWARDED_FOR']);

    expect($address->forwardedFor())->toBeNull();
});

it('ignores non-string address facts', function (): void {
    $_SERVER['REMOTE_ADDR'] = ['x'];
    $_SERVER['HTTP_X_FORWARDED_FOR'] = ['y'];

    $address = new SuperglobalClientAddress;

    expect($address->remoteAddress())->toBe('')
        ->and($address->forwardedFor())->toBeNull();
});

it('tells the time of the machine', function (): void {
    $before = time();
    $now = (new SystemClock)->now();

    expect($now)->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual(time());
});

it('logs a lockout on one line and fires the action', function (): void {
    $log = tempnam(sys_get_temp_dir(), 'portcullis-log');
    $previous = ini_set('error_log', $log);

    $lockout = new Lockout(new Subject(SubjectScope::Ip, str_repeat('a', 32)), 1_700_000_000, true);
    $received = [];
    add_action('portcullis/locked_out', static function (...$args) use (&$received): void {
        $received = $args;
    }, 10, 3);

    (new ErrorLogLockoutNotifier(new WordPressHookRegistrar))->lockedOut($lockout, ClientIp::tryFromString('203.0.113.9'), 'admin');

    $line = (string) file_get_contents($log);
    ini_set('error_log', (string) $previous);
    unlink($log);

    expect($line)->toContain('[portcullis] lockout scope='.SubjectScope::Ip->label().' ip=203.0.113.9 login="admin" until=2023-11-14T22:13:20Z tier=long')
        ->and(substr_count(trim($line), "\n"))->toBe(0)
        ->and($received[0])->toBe($lockout)
        ->and($received[2])->toBe('admin');
});

it('cannot be made to forge extra log lines through the submitted login', function (): void {
    $log = tempnam(sys_get_temp_dir(), 'portcullis-log');
    $previous = ini_set('error_log', $log);

    $lockout = new Lockout(new Subject(SubjectScope::Account, str_repeat('b', 32)), 1_700_000_000, false);
    $forged = "bob\n[portcullis] lockout scope=ip ip=1.2.3.4 login=\"x\" \"quoted\\\" ".str_repeat('z', 200);

    (new ErrorLogLockoutNotifier(new WordPressHookRegistrar))->lockedOut($lockout, ClientIp::tryFromString('203.0.113.9'), $forged);

    $content = trim((string) file_get_contents($log));
    ini_set('error_log', (string) $previous);
    unlink($log);

    expect(substr_count($content, "\n"))->toBe(0)
        ->and($content)->toContain('tier=regular')
        ->and($content)->toContain('login="bob?[portcullis]')
        ->and($content)->toContain('\"quoted\\\\\"')
        ->and($content)->not->toContain(str_repeat('z', 150));
});

it('registers, runs and removes callbacks through the plugin API', function (): void {
    $hooks = new WordPressHookRegistrar;
    $calls = [];
    $callback = static function (...$args) use (&$calls): void {
        $calls[] = $args;
    };

    $hooks->addAction('some_action', $callback, 5, 2);
    $hooks->doAction('some_action', 'a', 'b', 'c');

    expect($calls)->toBe([['a', 'b']]);

    $hooks->removeAction('some_action', $callback, 5);
    $hooks->doAction('some_action', 'again');

    expect($calls)->toHaveCount(1);

    $hooks->addFilter('some_filter', static fn (string $value, string $extra): string => $value.$extra, 10, 2);

    expect($hooks->applyFilters('some_filter', 'x', 'y'))->toBe('xy');
});
