<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Port\Out\AttemptStorePort;
use Pollora\Portcullis\Port\Out\ClockPort;

/**
 * Tells whether an attempt must be refused before its credentials are even checked.
 */
final class CheckLockout
{
    /**
     * @param  AttemptStorePort  $store  Where lockouts are kept.
     * @param  ClockPort  $clock  Current time.
     */
    public function __construct(
        private readonly AttemptStorePort $store,
        private readonly ClockPort $clock,
    ) {}

    /**
     * Timestamp the longest lockout among the subjects ends at, `null` when none applies.
     *
     * @param  list<Subject>  $subjects  Subjects of the attempt.
     */
    public function lockedUntil(array $subjects): ?int
    {
        if ($subjects === []) {
            return null;
        }

        return $this->store->lockedUntil($subjects, $this->clock->now());
    }
}
