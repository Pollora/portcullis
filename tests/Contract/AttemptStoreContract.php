<?php

declare(strict_types=1);

use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;

/*
 * The behaviour every AttemptStorePort implementation must reproduce.
 *
 * Not a test file on its own: each suite calls attemptStoreContract() with a
 * dataset of store factories — the in-memory reference from the unit suite, the
 * MySQL adapter from the integration suite — and every implementation is held to
 * exactly the same expectations.
 */

const CONTRACT_NOW = 1_700_000_000;

function contractSubject(string $seed, SubjectScope $scope = SubjectScope::Ip): Subject
{
    return new Subject($scope, hash('sha256', $seed, true));
}

/**
 * Registers the contract tests against a dataset of store factories.
 *
 * @param  string  $dataset  Name of a dataset yielding `Closure(): AttemptStorePort`.
 */
function attemptStoreContract(string $dataset): void
{
    it('increments failures within the window', function (Closure $store): void {
        $store = $store();
        $subject = contractSubject('a');

        $store->recordFailure($subject, CONTRACT_NOW, CONTRACT_NOW + 100);
        $state = $store->recordFailure($subject, CONTRACT_NOW + 10, CONTRACT_NOW + 110);

        expect($state->failures())->toBe(2)
            ->and($state->lockouts())->toBe(0)
            ->and($state->lockedUntil())->toBeNull()
            ->and($state->windowExpiresAt())->toBe(CONTRACT_NOW + 110);
    })->with($dataset);

    it('starts over once the window has expired', function (Closure $store): void {
        $store = $store();
        $subject = contractSubject('a');

        $store->recordFailure($subject, CONTRACT_NOW, CONTRACT_NOW + 100);
        $store->lock($subject, CONTRACT_NOW + 50, 1, false);
        $state = $store->recordFailure($subject, CONTRACT_NOW + 101, CONTRACT_NOW + 201);

        expect($state->failures())->toBe(1)
            ->and($state->lockouts())->toBe(0);
    })->with($dataset);

    it('applies a lockout only while the threshold is reached', function (Closure $store): void {
        $store = $store();
        $subject = contractSubject('a');

        $store->recordFailure($subject, CONTRACT_NOW, CONTRACT_NOW + 100);

        expect($store->lock($subject, CONTRACT_NOW + 50, 2, false))->toBeFalse();

        $store->recordFailure($subject, CONTRACT_NOW, CONTRACT_NOW + 100);

        expect($store->lock($subject, CONTRACT_NOW + 50, 2, false))->toBeTrue()
            // A concurrent request crossing the same threshold finds the failures
            // already reset and must not apply — nor extend — the lockout again.
            ->and($store->lock($subject, CONTRACT_NOW + 80, 2, false))->toBeFalse()
            ->and($store->lockedUntil([$subject], CONTRACT_NOW))->toBe(CONTRACT_NOW + 50);
    })->with($dataset);

    it('counts regular lockouts and resets them on a long one', function (Closure $store): void {
        $store = $store();
        $subject = contractSubject('a');

        $store->recordFailure($subject, CONTRACT_NOW, CONTRACT_NOW + 100);
        $store->lock($subject, CONTRACT_NOW + 1, 1, false);
        $store->recordFailure($subject, CONTRACT_NOW, CONTRACT_NOW + 100);
        $store->lock($subject, CONTRACT_NOW + 1, 1, false);

        expect($store->recordFailure($subject, CONTRACT_NOW, CONTRACT_NOW + 100)->lockouts())->toBe(2);

        $store->lock($subject, CONTRACT_NOW + 1, 1, true);

        expect($store->recordFailure($subject, CONTRACT_NOW, CONTRACT_NOW + 100)->lockouts())->toBe(0);
    })->with($dataset);

    it('ignores lockouts that have ended', function (Closure $store): void {
        $store = $store();
        $subject = contractSubject('a');

        $store->recordFailure($subject, CONTRACT_NOW, CONTRACT_NOW + 100);
        $store->lock($subject, CONTRACT_NOW + 50, 1, false);

        expect($store->lockedUntil([$subject], CONTRACT_NOW + 49))->toBe(CONTRACT_NOW + 50)
            ->and($store->lockedUntil([$subject], CONTRACT_NOW + 50))->toBeNull()
            ->and($store->lockedUntil([contractSubject('unknown')], CONTRACT_NOW))->toBeNull();
    })->with($dataset);

    it('keeps scopes apart for the same key', function (Closure $store): void {
        $store = $store();
        $ip = contractSubject('same', SubjectScope::Ip);
        $account = contractSubject('same', SubjectScope::Account);

        $store->recordFailure($ip, CONTRACT_NOW, CONTRACT_NOW + 100);
        $store->lock($ip, CONTRACT_NOW + 50, 1, false);

        expect($store->lockedUntil([$account], CONTRACT_NOW))->toBeNull()
            ->and($store->recordFailure($account, CONTRACT_NOW, CONTRACT_NOW + 100)->failures())->toBe(1);
    })->with($dataset);

    it('clears subjects entirely', function (Closure $store): void {
        $store = $store();
        $subject = contractSubject('a');

        $store->recordFailure($subject, CONTRACT_NOW, CONTRACT_NOW + 100);
        $store->lock($subject, CONTRACT_NOW + 50, 1, false);
        $store->clear([$subject]);

        expect($store->lockedUntil([$subject], CONTRACT_NOW))->toBeNull()
            ->and($store->recordFailure($subject, CONTRACT_NOW, CONTRACT_NOW + 100)->failures())->toBe(1);
    })->with($dataset);

    it('purges expired counters but keeps running lockouts', function (Closure $store): void {
        $store = $store();
        $expired = contractSubject('expired');
        $locked = contractSubject('locked');
        $active = contractSubject('active');

        $store->recordFailure($expired, CONTRACT_NOW, CONTRACT_NOW + 10);
        $store->recordFailure($locked, CONTRACT_NOW, CONTRACT_NOW + 10);
        $store->lock($locked, CONTRACT_NOW + 1000, 1, false);
        $store->recordFailure($active, CONTRACT_NOW, CONTRACT_NOW + 1000);

        expect($store->purgeExpired(CONTRACT_NOW + 20, 100))->toBe(1)
            ->and($store->lockedUntil([$locked], CONTRACT_NOW + 20))->toBe(CONTRACT_NOW + 1000)
            ->and($store->recordFailure($active, CONTRACT_NOW + 20, CONTRACT_NOW + 1000)->failures())->toBe(2);
    })->with($dataset);

    it('purges in batches', function (Closure $store): void {
        $store = $store();

        foreach (range(1, 5) as $i) {
            $store->recordFailure(contractSubject("s{$i}"), CONTRACT_NOW, CONTRACT_NOW + 10);
        }

        expect($store->purgeExpired(CONTRACT_NOW + 20, 2))->toBe(2)
            ->and($store->purgeExpired(CONTRACT_NOW + 20, 10))->toBe(3)
            ->and($store->purgeExpired(CONTRACT_NOW + 20, 10))->toBe(0);
    })->with($dataset);

    it('lists running lockouts, longest first', function (Closure $store): void {
        $store = $store();

        foreach (['short' => 100, 'long' => 900, 'ended' => 5] as $seed => $duration) {
            $subject = contractSubject($seed);
            $store->recordFailure($subject, CONTRACT_NOW, CONTRACT_NOW + 1000);
            $store->lock($subject, CONTRACT_NOW + $duration, 1, false);
        }

        $lockouts = $store->activeLockouts(CONTRACT_NOW + 10, 10);

        expect(array_map(fn ($l) => $l->lockedUntil(), $lockouts))->toBe([CONTRACT_NOW + 900, CONTRACT_NOW + 100])
            ->and($lockouts[0]->subject()->equals(contractSubject('long')))->toBeTrue()
            ->and($store->activeLockouts(CONTRACT_NOW + 10, 1))->toHaveCount(1);
    })->with($dataset);
}
