<?php

declare(strict_types=1);

use Pollora\Portcullis\Domain\Exception\InvalidThrottlePolicyException;
use Pollora\Portcullis\Domain\Model\ScopePolicy;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;

it('ships the Limit Login Attempts Reloaded defaults per address', function (): void {
    $ip = ThrottlePolicy::defaults()->forScope(SubjectScope::Ip);

    expect($ip?->maxRetries())->toBe(4)
        ->and($ip?->lockoutDuration())->toBe(1200)
        ->and($ip?->maxLockouts())->toBe(4)
        ->and($ip?->longLockoutDuration())->toBe(86400)
        ->and(ThrottlePolicy::defaults()->retriesValidity())->toBe(86400);
});

it('counts per account by default, with a higher threshold', function (): void {
    expect(ThrottlePolicy::defaults()->forScope(SubjectScope::Account)?->maxRetries())->toBe(20);
});

it('can count per address only', function (): void {
    $policy = new ThrottlePolicy(3600, new ScopePolicy(5, 600, 0, 0), null);

    expect($policy->forScope(SubjectScope::Account))->toBeNull();
});

it('escalates to the long lockout on the configured lockout', function (): void {
    $scope = new ScopePolicy(4, 1200, 4, 86400);

    expect($scope->durationFor(0))->toBe(1200)
        ->and($scope->durationFor(2))->toBe(1200)
        ->and($scope->isLongLockout(3))->toBeTrue()
        ->and($scope->durationFor(3))->toBe(86400);
});

it('never escalates when the long tier is off', function (): void {
    $scope = new ScopePolicy(4, 1200, 0, 0);

    expect($scope->isLongLockout(100))->toBeFalse()
        ->and($scope->durationFor(100))->toBe(1200);
});

it('rejects incoherent thresholds', function (callable $build): void {
    $build();
})->with([
    'no retry allowed' => [fn () => new ScopePolicy(0, 1200, 4, 86400)],
    'zero lockout' => [fn () => new ScopePolicy(4, 0, 4, 86400)],
    'negative lockouts' => [fn () => new ScopePolicy(4, 1200, -1, 86400)],
    'long shorter than regular' => [fn () => new ScopePolicy(4, 1200, 4, 600)],
    'validity shorter than a lockout' => [fn () => new ThrottlePolicy(600, new ScopePolicy(4, 1200, 0, 0), null)],
])->throws(InvalidThrottlePolicyException::class);
