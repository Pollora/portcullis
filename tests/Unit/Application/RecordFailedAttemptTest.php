<?php

declare(strict_types=1);

use Pollora\Portcullis\Application\Service\CheckLockout;
use Pollora\Portcullis\Application\Service\ClearAttempts;
use Pollora\Portcullis\Application\Service\RecordFailedAttempt;
use Pollora\Portcullis\Domain\Model\ScopePolicy;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;
use Pollora\Portcullis\Tests\Support\FrozenClock;
use Pollora\Portcullis\Tests\Support\InMemoryAttemptStore;

function subject(SubjectScope $scope = SubjectScope::Ip, string $seed = 'a'): Subject
{
    return new Subject($scope, hash('sha256', $seed, true));
}

beforeEach(function (): void {
    $this->store = new InMemoryAttemptStore;
    $this->clock = new FrozenClock;
    $this->policy = new ThrottlePolicy(
        86400,
        new ScopePolicy(4, 1200, 4, 86400),
        new ScopePolicy(20, 1200, 0, 0),
    );
    $this->record = new RecordFailedAttempt($this->store, $this->policy, $this->clock);
    $this->check = new CheckLockout($this->store, $this->clock);
});

it('does not lock out below the threshold', function (): void {
    $ip = subject();

    foreach (range(1, 3) as $_) {
        expect($this->record->record([$ip]))->toBe([]);
    }

    expect($this->check->lockedUntil([$ip]))->toBeNull();
});

it('locks out on the threshold, for the regular duration', function (): void {
    $ip = subject();

    foreach (range(1, 3) as $_) {
        $this->record->record([$ip]);
    }

    $lockouts = $this->record->record([$ip]);

    expect($lockouts)->toHaveCount(1)
        ->and($lockouts[0]->isLong())->toBeFalse()
        ->and($lockouts[0]->lockedUntil())->toBe($this->clock->now() + 1200)
        ->and($this->check->lockedUntil([$ip]))->toBe($this->clock->now() + 1200);
});

it('lets the subject retry once the lockout has ended', function (): void {
    $ip = subject();

    foreach (range(1, 4) as $_) {
        $this->record->record([$ip]);
    }

    $this->clock->advance(1200);

    expect($this->check->lockedUntil([$ip]))->toBeNull()
        ->and($this->record->record([$ip]))->toBe([]);
});

it('escalates to the long lockout on the fourth lockout', function (): void {
    $ip = subject();
    $durations = [];

    foreach (range(1, 4) as $_) {
        foreach (range(1, 4) as $__) {
            $lockouts = $this->record->record([$ip]);
        }

        $durations[] = $lockouts[0]->lockedUntil() - $this->clock->now();
        $this->clock->advance($lockouts[0]->lockedUntil() - $this->clock->now());
    }

    expect($durations)->toBe([1200, 1200, 1200, 86400]);
});

it('starts a new cycle of regular lockouts after a long one', function (): void {
    $ip = subject();

    foreach (range(1, 16) as $_) {
        $lockouts = $this->record->record([$ip]);

        if ($lockouts !== []) {
            $this->clock->advance($lockouts[0]->lockedUntil() - $this->clock->now());
        }
    }

    foreach (range(1, 4) as $_) {
        $lockouts = $this->record->record([$ip]);
    }

    expect($lockouts[0]->isLong())->toBeFalse();
});

it('forgets failures once the validity window has passed', function (): void {
    $ip = subject();

    foreach (range(1, 3) as $_) {
        $this->record->record([$ip]);
    }

    $this->clock->advance(86401);

    expect($this->record->record([$ip]))->toBe([]);
});

it('counts the account on its own threshold, across addresses', function (): void {
    // A botnet trying one account from many addresses never trips the
    // per-address threshold; the account counter catches it.
    $account = subject(SubjectScope::Account, 'admin');
    $lockouts = [];

    foreach (range(1, 20) as $i) {
        $lockouts = $this->record->record([subject(SubjectScope::Ip, "ip-{$i}"), $account]);
    }

    expect($lockouts)->toHaveCount(1)
        ->and($lockouts[0]->subject()->equals($account))->toBeTrue()
        ->and($this->check->lockedUntil([subject(SubjectScope::Ip, 'fresh'), $account]))->not->toBeNull();
});

it('skips scopes the policy does not count', function (): void {
    $record = new RecordFailedAttempt(
        $this->store,
        new ThrottlePolicy(86400, new ScopePolicy(4, 1200, 4, 86400), null),
        $this->clock,
    );

    foreach (range(1, 50) as $i) {
        $record->record([subject(SubjectScope::Ip, "ip-{$i}"), subject(SubjectScope::Account, 'admin')]);
    }

    expect($this->check->lockedUntil([subject(SubjectScope::Account, 'admin')]))->toBeNull();
});

it('reports the longest lockout among the subjects', function (): void {
    $ip = subject();
    $account = subject(SubjectScope::Account, 'admin');

    $this->store->recordFailure($ip, $this->clock->now(), $this->clock->now() + 86400);
    $this->store->lock($ip, $this->clock->now() + 100, 1, false);
    $this->store->recordFailure($account, $this->clock->now(), $this->clock->now() + 86400);
    $this->store->lock($account, $this->clock->now() + 500, 1, false);

    expect($this->check->lockedUntil([$ip, $account]))->toBe($this->clock->now() + 500);
});

it('clears failures and lockouts after a successful login', function (): void {
    $ip = subject();

    foreach (range(1, 4) as $_) {
        $this->record->record([$ip]);
    }

    (new ClearAttempts($this->store))->clear([$ip]);

    expect($this->check->lockedUntil([$ip]))->toBeNull()
        ->and($this->record->record([$ip]))->toBe([]);
});
