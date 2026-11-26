<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentThrottleSettings;
use Pollora\Portcullis\Domain\Exception\InvalidThrottlePolicyException;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;

const PORTCULLIS_THROTTLE_KEYS = [
    'PORTCULLIS_THROTTLE_ENABLED', 'PORTCULLIS_MAX_RETRIES', 'PORTCULLIS_LOCKOUT_DURATION',
    'PORTCULLIS_MAX_LOCKOUTS', 'PORTCULLIS_LONG_LOCKOUT_DURATION', 'PORTCULLIS_RETRIES_VALIDITY',
    'PORTCULLIS_ACCOUNT_MAX_RETRIES', 'PORTCULLIS_ACCOUNT_LOCKOUT_DURATION', 'PORTCULLIS_TRUSTED_PROXIES',
    'PORTCULLIS_ALLOWLIST', 'PORTCULLIS_SECRET', 'PORTCULLIS_GENERIC_LOGIN_ERRORS',
];

beforeEach(function (): void {
    foreach (PORTCULLIS_THROTTLE_KEYS as $key) {
        unset($_ENV[$key], $_SERVER[$key]);
    }
});

afterEach(function (): void {
    foreach (PORTCULLIS_THROTTLE_KEYS as $key) {
        unset($_ENV[$key]);
    }
});

it('is enabled unless explicitly switched off', function (): void {
    expect((new EnvironmentThrottleSettings)->isEnabled())->toBeTrue();

    $_ENV['PORTCULLIS_THROTTLE_ENABLED'] = 'false';

    expect((new EnvironmentThrottleSettings)->isEnabled())->toBeFalse();
});

it('applies the documented defaults when nothing is configured', function (): void {
    $settings = (new EnvironmentThrottleSettings)->settings();
    $ip = scopePolicy($settings->policy(), SubjectScope::Ip);
    $account = scopePolicy($settings->policy(), SubjectScope::Account);

    expect($ip->maxRetries())->toBe(ThrottlePolicy::DEFAULT_MAX_RETRIES)
        ->and($ip->lockoutDuration())->toBe(ThrottlePolicy::DEFAULT_LOCKOUT_DURATION)
        ->and($account)->not->toBeNull()
        ->and($account->maxRetries())->toBe(ThrottlePolicy::DEFAULT_ACCOUNT_MAX_RETRIES)
        ->and($settings->policy()->retriesValidity())->toBe(ThrottlePolicy::DEFAULT_RETRIES_VALIDITY)
        ->and($settings->trustedProxies())->toBe([])
        ->and($settings->allowlist())->toBe([])
        ->and($settings->genericErrors())->toBeTrue();
});

it('reads every threshold from the environment', function (): void {
    $_ENV['PORTCULLIS_MAX_RETRIES'] = '3';
    $_ENV['PORTCULLIS_LOCKOUT_DURATION'] = '600';
    $_ENV['PORTCULLIS_MAX_LOCKOUTS'] = '2';
    $_ENV['PORTCULLIS_LONG_LOCKOUT_DURATION'] = '7200';
    $_ENV['PORTCULLIS_RETRIES_VALIDITY'] = '1800';
    $_ENV['PORTCULLIS_ACCOUNT_MAX_RETRIES'] = '8';
    $_ENV['PORTCULLIS_ACCOUNT_LOCKOUT_DURATION'] = '900';

    $policy = (new EnvironmentThrottleSettings)->settings()->policy();
    $ip = scopePolicy($policy, SubjectScope::Ip);
    $account = scopePolicy($policy, SubjectScope::Account);

    expect($ip->maxRetries())->toBe(3)
        ->and($ip->lockoutDuration())->toBe(600)
        ->and($ip->maxLockouts())->toBe(2)
        ->and($ip->longLockoutDuration())->toBe(7200)
        ->and($policy->retriesValidity())->toBe(1800)
        ->and($account->maxRetries())->toBe(8)
        ->and($account->lockoutDuration())->toBe(900);
});

it('lets the account lockout follow the address lockout when not configured on its own', function (): void {
    $_ENV['PORTCULLIS_LOCKOUT_DURATION'] = '450';

    $account = scopePolicy((new EnvironmentThrottleSettings)->settings()->policy(), SubjectScope::Account);

    expect($account->lockoutDuration())->toBe(450);
});

it('counts per address only when the account threshold is 0', function (): void {
    $_ENV['PORTCULLIS_ACCOUNT_MAX_RETRIES'] = '0';

    expect((new EnvironmentThrottleSettings)->settings()->policy()->forScope(SubjectScope::Account))->toBeNull();
});

it('parses the trusted proxies and the allowlist as ranges', function (): void {
    $_ENV['PORTCULLIS_TRUSTED_PROXIES'] = '10.0.0.0/8, 192.168.1.1';
    $_ENV['PORTCULLIS_ALLOWLIST'] = '203.0.113.0/24';

    $settings = (new EnvironmentThrottleSettings)->settings();

    expect($settings->trustedProxies())->toHaveCount(2)
        ->and($settings->allowlist())->toHaveCount(1)
        ->and($settings->isAllowlisted(clientIp('203.0.113.77')))->toBeTrue()
        ->and($settings->isAllowlisted(clientIp('203.0.114.1')))->toBeFalse();
});

it('switches generic login errors off on request', function (): void {
    $_ENV['PORTCULLIS_GENERIC_LOGIN_ERRORS'] = 'off';

    expect((new EnvironmentThrottleSettings)->settings()->genericErrors())->toBeFalse();
});

it('uses the configured secret', function (): void {
    $_ENV['PORTCULLIS_SECRET'] = 'a-secret-that-is-long-enough-for-the-subjects';

    expect((new EnvironmentThrottleSettings)->settings()->secret())->toBe('a-secret-that-is-long-enough-for-the-subjects');
});

it('derives a stable secret of full length when none is configured', function (): void {
    $derived = EnvironmentThrottleSettings::derivedSecret();

    expect((new EnvironmentThrottleSettings)->settings()->secret())->toBe($derived)
        ->and($derived)->toMatch('/^[0-9a-f]{64}$/')
        ->and(EnvironmentThrottleSettings::derivedSecret())->toBe($derived);
});

it('rejects a malformed threshold instead of silently using a default', function (): void {
    $_ENV['PORTCULLIS_MAX_RETRIES'] = 'many';

    (new EnvironmentThrottleSettings)->settings();
})->throws(InvalidThrottlePolicyException::class);
