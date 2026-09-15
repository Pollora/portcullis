<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

use Pollora\Portcullis\Domain\Model\Lockout;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;
use Pollora\Portcullis\Port\Out\AttemptStorePort;
use Pollora\Portcullis\Port\Out\ClockPort;

/**
 * Counts a failed login and applies the lockouts it triggers.
 */
final class RecordFailedAttempt
{
    /**
     * @param  AttemptStorePort  $store  Where counters are kept.
     * @param  ThrottlePolicy  $policy  Thresholds and durations.
     * @param  ClockPort  $clock  Current time.
     */
    public function __construct(
        private readonly AttemptStorePort $store,
        private readonly ThrottlePolicy $policy,
        private readonly ClockPort $clock,
    ) {}

    /**
     * Records the failure against every subject and returns the lockouts it triggered.
     *
     * Subjects whose scope the policy does not count are skipped. Thresholds are
     * compared with `>=`, never with a modulo: concurrent failures can push a
     * counter past its threshold before the lockout is written, and the lockout
     * must still apply.
     *
     * @param  list<Subject>  $subjects  Subjects of the attempt.
     * @return list<Lockout> Lockouts applied by this very call.
     */
    public function record(array $subjects): array
    {
        $now = $this->clock->now();
        $windowExpiresAt = $now + $this->policy->retriesValidity();
        $lockouts = [];

        foreach ($subjects as $subject) {
            $scope = $this->policy->forScope($subject->scope());

            if ($scope === null) {
                continue;
            }

            $state = $this->store->recordFailure($subject, $now, $windowExpiresAt);

            if ($state->failures() < $scope->maxRetries()) {
                continue;
            }

            $long = $scope->isLongLockout($state->lockouts());
            $lockedUntil = $now + $scope->durationFor($state->lockouts());

            if ($this->store->lock($subject, $lockedUntil, $scope->maxRetries(), $long)) {
                $lockouts[] = new Lockout($subject, $lockedUntil, $long);
            }
        }

        return $lockouts;
    }
}
