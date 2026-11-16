<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Tests\Support;

use Pollora\Portcullis\Domain\Model\AttemptState;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Port\Out\AttemptStorePort;
use RuntimeException;

/**
 * A store whose backend is down: every call fails the way the database adapter does.
 */
final class ExplodingAttemptStore implements AttemptStorePort
{
    public int $calls = 0;

    public function lockedUntil(array $subjects, int $now): ?int
    {
        $this->fail();
    }

    public function recordFailure(Subject $subject, int $now, int $windowExpiresAt): AttemptState
    {
        $this->fail();
    }

    public function lock(Subject $subject, int $lockedUntil, int $threshold, bool $long): bool
    {
        $this->fail();
    }

    public function clear(array $subjects): void
    {
        $this->fail();
    }

    public function purgeExpired(int $now, int $limit): int
    {
        $this->fail();
    }

    public function activeLockouts(int $now, int $limit): array
    {
        $this->fail();
    }

    private function fail(): never
    {
        $this->calls++;

        throw new RuntimeException('[portcullis] connection refused');
    }
}
