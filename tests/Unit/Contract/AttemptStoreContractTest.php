<?php

declare(strict_types=1);

use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Port\Out\AttemptStorePort;
use Pollora\Portcullis\Tests\Support\InMemoryAttemptStore;

/*
 * The behaviour every AttemptStorePort implementation must reproduce.
 *
 * Adapters backed by a real service (MySQL, Redis) cannot run in the unit
 * suite; add them to the dataset below from an integration suite that has the
 * service at hand, and they get checked against exactly the same expectations.
 */

dataset('stores', [
    'in memory' => [fn (): AttemptStorePort => new InMemoryAttemptStore],
]);

const NOW = 1_700_000_000;

function contractSubject(string $seed, SubjectScope $scope = SubjectScope::Ip): Subject
{
    return new Subject($scope, hash('sha256', $seed, true));
}

it('increments failures within the window', function (Closure $store): void {
    $store = $store();
    $subject = contractSubject('a');

    $store->recordFailure($subject, NOW, NOW + 100);
    $state = $store->recordFailure($subject, NOW + 10, NOW + 110);

    expect($state->failures())->toBe(2)
        ->and($state->lockouts())->toBe(0)
        ->and($state->lockedUntil())->toBeNull()
        ->and($state->windowExpiresAt())->toBe(NOW + 110);
})->with('stores');

it('starts over once the window has expired', function (Closure $store): void {
    $store = $store();
    $subject = contractSubject('a');

    $store->recordFailure($subject, NOW, NOW + 100);
    $store->lock($subject, NOW + 50, 1, false);
    $state = $store->recordFailure($subject, NOW + 101, NOW + 201);

    expect($state->failures())->toBe(1)
        ->and($state->lockouts())->toBe(0);
})->with('stores');

it('applies a lockout only while the threshold is reached', function (Closure $store): void {
    $store = $store();
    $subject = contractSubject('a');

    $store->recordFailure($subject, NOW, NOW + 100);

    expect($store->lock($subject, NOW + 50, 2, false))->toBeFalse();

    $store->recordFailure($subject, NOW, NOW + 100);

    expect($store->lock($subject, NOW + 50, 2, false))->toBeTrue()
        // A concurrent request crossing the same threshold finds the failures
        // already reset and must not apply — nor extend — the lockout again.
        ->and($store->lock($subject, NOW + 80, 2, false))->toBeFalse()
        ->and($store->lockedUntil([$subject], NOW))->toBe(NOW + 50);
})->with('stores');

it('counts regular lockouts and resets them on a long one', function (Closure $store): void {
    $store = $store();
    $subject = contractSubject('a');

    $store->recordFailure($subject, NOW, NOW + 100);
    $store->lock($subject, NOW + 1, 1, false);
    $store->recordFailure($subject, NOW, NOW + 100);
    $store->lock($subject, NOW + 1, 1, false);

    expect($store->recordFailure($subject, NOW, NOW + 100)->lockouts())->toBe(2);

    $store->lock($subject, NOW + 1, 1, true);

    expect($store->recordFailure($subject, NOW, NOW + 100)->lockouts())->toBe(0);
})->with('stores');

it('ignores lockouts that have ended', function (Closure $store): void {
    $store = $store();
    $subject = contractSubject('a');

    $store->recordFailure($subject, NOW, NOW + 100);
    $store->lock($subject, NOW + 50, 1, false);

    expect($store->lockedUntil([$subject], NOW + 49))->toBe(NOW + 50)
        ->and($store->lockedUntil([$subject], NOW + 50))->toBeNull()
        ->and($store->lockedUntil([contractSubject('unknown')], NOW))->toBeNull();
})->with('stores');

it('keeps scopes apart for the same key', function (Closure $store): void {
    $store = $store();
    $ip = contractSubject('same', SubjectScope::Ip);
    $account = contractSubject('same', SubjectScope::Account);

    $store->recordFailure($ip, NOW, NOW + 100);
    $store->lock($ip, NOW + 50, 1, false);

    expect($store->lockedUntil([$account], NOW))->toBeNull()
        ->and($store->recordFailure($account, NOW, NOW + 100)->failures())->toBe(1);
})->with('stores');

it('clears subjects entirely', function (Closure $store): void {
    $store = $store();
    $subject = contractSubject('a');

    $store->recordFailure($subject, NOW, NOW + 100);
    $store->lock($subject, NOW + 50, 1, false);
    $store->clear([$subject]);

    expect($store->lockedUntil([$subject], NOW))->toBeNull()
        ->and($store->recordFailure($subject, NOW, NOW + 100)->failures())->toBe(1);
})->with('stores');

it('purges expired counters but keeps running lockouts', function (Closure $store): void {
    $store = $store();
    $expired = contractSubject('expired');
    $locked = contractSubject('locked');
    $active = contractSubject('active');

    $store->recordFailure($expired, NOW, NOW + 10);
    $store->recordFailure($locked, NOW, NOW + 10);
    $store->lock($locked, NOW + 1000, 1, false);
    $store->recordFailure($active, NOW, NOW + 1000);

    expect($store->purgeExpired(NOW + 20, 100))->toBe(1)
        ->and($store->lockedUntil([$locked], NOW + 20))->toBe(NOW + 1000)
        ->and($store->recordFailure($active, NOW + 20, NOW + 1000)->failures())->toBe(2);
})->with('stores');

it('purges in batches', function (Closure $store): void {
    $store = $store();

    foreach (range(1, 5) as $i) {
        $store->recordFailure(contractSubject("s{$i}"), NOW, NOW + 10);
    }

    expect($store->purgeExpired(NOW + 20, 2))->toBe(2)
        ->and($store->purgeExpired(NOW + 20, 10))->toBe(3)
        ->and($store->purgeExpired(NOW + 20, 10))->toBe(0);
})->with('stores');

it('lists running lockouts, longest first', function (Closure $store): void {
    $store = $store();

    foreach (['short' => 100, 'long' => 900, 'ended' => 5] as $seed => $duration) {
        $subject = contractSubject($seed);
        $store->recordFailure($subject, NOW, NOW + 1000);
        $store->lock($subject, NOW + $duration, 1, false);
    }

    $lockouts = $store->activeLockouts(NOW + 10, 10);

    expect(array_map(fn ($l) => $l->lockedUntil(), $lockouts))->toBe([NOW + 900, NOW + 100])
        ->and($lockouts[0]->subject()->equals(contractSubject('long')))->toBeTrue()
        ->and($store->activeLockouts(NOW + 10, 1))->toHaveCount(1);
})->with('stores');
