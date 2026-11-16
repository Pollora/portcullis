<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentFeatureToggle;
use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentSlugProvider;
use Pollora\Portcullis\Application\Service\CheckLockout;
use Pollora\Portcullis\Application\Service\ClearAttempts;
use Pollora\Portcullis\Application\Service\RewriteLoginUrl;
use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\Lockout;
use Pollora\Portcullis\Domain\Model\LoginSlug;
use Pollora\Portcullis\Domain\Model\RequestPath;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Tests\Support\ExplodingAttemptStore;
use Pollora\Portcullis\Tests\Support\FrozenClock;

afterEach(function (): void {
    foreach (array_keys($_ENV) as $key) {
        if (str_starts_with((string) $key, 'PORTCULLIS_TEST_')) {
            unset($_ENV[$key]);
        }
    }
});

it('runs out of time for a lockout without ever going negative', function (): void {
    $lockout = new Lockout(new Subject(SubjectScope::Ip, str_repeat('a', 32)), 1_000, false);

    expect($lockout->secondsLeft(400))->toBe(600)
        ->and($lockout->secondsLeft(1_000))->toBe(0)
        ->and($lockout->secondsLeft(5_000))->toBe(0);
});

it('refuses a subject key that is not 32 bytes long', function (string $key): void {
    new Subject(SubjectScope::Ip, $key);
})->with(['', 'short', 33 => str_repeat('a', 33)])->throws(InvalidArgumentException::class, '32 bytes');

it('refuses a hexadecimal key that is not 64 hexadecimal characters', function (string $hex): void {
    Subject::fromHex(SubjectScope::Account, $hex);
})->with(['', 'zz'.'00', 'g'.str_repeat('0', 63), 65 => str_repeat('a', 65)])->throws(InvalidArgumentException::class, '64 hexadecimal');

it('round-trips a key through its hexadecimal form', function (): void {
    $subject = new Subject(SubjectScope::Account, hash('sha256', 'x', true));
    $copy = Subject::fromHex(SubjectScope::Account, strtoupper($subject->hexKey()));

    expect($copy->key())->toBe($subject->key())
        ->and($copy->equals($subject))->toBeTrue()
        ->and($copy->equals(new Subject(SubjectScope::Ip, $subject->key())))->toBeFalse()
        ->and($copy->equals(new Subject(SubjectScope::Account, hash('sha256', 'y', true))))->toBeFalse();
});

it('prints an address in canonical form', function (): void {
    expect((string) ClientIp::tryFromString('2001:0DB8:0:0:0:0:0:1'))->toBe('2001:db8::1')
        ->and((string) ClientIp::tryFromString(' 203.0.113.9 '))->toBe('203.0.113.9');
});

it('rejects what is not an address, scoped IPv6 and ports included', function (string $value): void {
    expect(ClientIp::tryFromString($value))->toBeNull();
})->with(['', 'localhost', '203.0.113.9:80', 'fe80::1%eth0', '256.1.1.1', '1.2.3']);

it('prints a request path normalised', function (): void {
    expect((string) RequestPath::fromRequestUri('/Wp-Admin//?x=1'))->toBe('Wp-Admin')
        ->and(RequestPath::fromRequestUri('/index.php/acces-secret/')->value())->toBe('acces-secret')
        ->and(RequestPath::fromRequestUri('/acces%2Dsecret')->value())->toBe('acces-secret');
});

it('reads a request URI that cannot be parsed as an empty path', function (): void {
    expect(RequestPath::fromRequestUri('http:///')->value())->toBe('');
});

it('leaves a URL it cannot parse untouched, rather than guessing where the login lives', function (): void {
    $slug = LoginSlug::fromString('acces-secret');

    expect((new RewriteLoginUrl)->rewrite('http:///wp-login.php', 'https://example.test', $slug))->toBe('http:///wp-login.php');
});

it('asks the store nothing for no subjects', function (): void {
    $store = new ExplodingAttemptStore;

    expect((new CheckLockout($store, new FrozenClock))->lockedUntil([]))->toBeNull();

    (new ClearAttempts($store))->clear([]);

    expect($store->calls)->toBe(0);
});

it('reads the kill switch from a constant before the environment', function (): void {
    define('PORTCULLIS_TEST_TOGGLE_OFF', 'false');
    $_ENV['PORTCULLIS_TEST_TOGGLE_OFF'] = 'true';

    expect((new EnvironmentFeatureToggle('PORTCULLIS_TEST_TOGGLE_OFF'))->state()->isEnabled())->toBeFalse();
});

it('reads the kill switch from a boolean constant', function (): void {
    define('PORTCULLIS_TEST_TOGGLE_BOOL', false);

    expect((new EnvironmentFeatureToggle('PORTCULLIS_TEST_TOGGLE_BOOL'))->state()->isEnabled())->toBeFalse();
});

it('treats a kill switch constant defined as null as unset, and moves on to the next key', function (): void {
    define('PORTCULLIS_TEST_TOGGLE_NULL', null);
    $_ENV['PORTCULLIS_TEST_TOGGLE_NEXT'] = 'off';

    expect((new EnvironmentFeatureToggle('PORTCULLIS_TEST_TOGGLE_NULL', 'PORTCULLIS_TEST_TOGGLE_NEXT'))->state()->isEnabled())->toBeFalse()
        ->and((new EnvironmentFeatureToggle('PORTCULLIS_TEST_TOGGLE_NULL'))->state()->isEnabled())->toBeTrue();
});

it('reads the kill switch from the environment, and is enabled when nothing is set', function (): void {
    $_ENV['PORTCULLIS_TEST_TOGGLE_ENV'] = 'false';

    expect((new EnvironmentFeatureToggle('PORTCULLIS_TEST_TOGGLE_ENV'))->state()->isEnabled())->toBeFalse()
        ->and((new EnvironmentFeatureToggle('PORTCULLIS_TEST_TOGGLE_NOPE'))->state()->isEnabled())->toBeTrue();
});

it('defaults to the 2.x then the 1.x kill switch names', function (): void {
    $toggle = new EnvironmentFeatureToggle;

    expect($toggle->state()->isEnabled())->toBeTrue()
        ->and(EnvironmentFeatureToggle::KEY)->toBe('PORTCULLIS_ENABLED')
        ->and(EnvironmentFeatureToggle::LEGACY_KEY)->toBe('HIDDEN_LOGIN_ENABLED');
});

it('reads the slug from a constant before the environment', function (): void {
    define('PORTCULLIS_TEST_SLUG_CONST', 'from-constant');
    $_ENV['PORTCULLIS_TEST_SLUG_CONST'] = 'from-env';

    expect((new EnvironmentSlugProvider('PORTCULLIS_TEST_SLUG_CONST'))->slug())->toBe('from-constant');
});

it('ignores a slug constant that is null, blank or not a string, and uses the next key', function (): void {
    define('PORTCULLIS_TEST_SLUG_NULL', null);
    define('PORTCULLIS_TEST_SLUG_BLANK', '  ');
    define('PORTCULLIS_TEST_SLUG_INT', 12345);
    $_ENV['PORTCULLIS_TEST_SLUG_LAST'] = 'last-one';

    $provider = new EnvironmentSlugProvider('PORTCULLIS_TEST_SLUG_NULL', 'PORTCULLIS_TEST_SLUG_BLANK', 'PORTCULLIS_TEST_SLUG_INT', 'PORTCULLIS_TEST_SLUG_LAST');

    expect($provider->slug())->toBe('last-one');
});

it('has no slug when nothing is configured', function (): void {
    expect((new EnvironmentSlugProvider('PORTCULLIS_TEST_SLUG_NOPE'))->slug())->toBeNull();
});
