<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Tests\Support;

use Closure;
use LogicException;
use Pollora\Portcullis\Domain\Model\AttemptState;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Port\Out\AttemptStorePort;

/**
 * A store that only knows how to purge, answering with whatever a closure says
 * and counting the calls.
 */
final class CountingPurgeStore implements AttemptStorePort
{
    public int $calls = 0;

    /**
     * @param  Closure(int, int): int  $answer  Receives the call number and the batch limit.
     */
    public function __construct(private readonly Closure $answer) {}

    public function lockedUntil(array $subjects, int $now): ?int
    {
        return null;
    }

    public function recordFailure(Subject $subject, int $now, int $windowExpiresAt): AttemptState
    {
        throw new LogicException('Not used.');
    }

    public function lock(Subject $subject, int $lockedUntil, int $threshold, bool $long): bool
    {
        return false;
    }

    public function clear(array $subjects): void {}

    public function purgeExpired(int $now, int $limit): int
    {
        $this->calls++;

        return ($this->answer)($this->calls, $limit);
    }

    public function activeLockouts(int $now, int $limit): array
    {
        return [];
    }
}
