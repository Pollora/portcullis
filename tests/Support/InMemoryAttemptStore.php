<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Tests\Support;

use Pollora\Portcullis\Domain\Model\AttemptState;
use Pollora\Portcullis\Domain\Model\Lockout;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Port\Out\AttemptStorePort;

/**
 * Reference implementation of {@see AttemptStorePort}, kept in memory.
 *
 * Single-threaded, so atomicity comes for free; what it pins down is the
 * *semantics* every real adapter has to reproduce, which the contract tests
 * exercise against it.
 */
final class InMemoryAttemptStore implements AttemptStorePort
{
    /**
     * @var array<string, array{subject: Subject, failures: int, lockouts: int, locked_until: int|null, window_expires_at: int}>
     */
    private array $rows = [];

    /**
     * {@inheritDoc}
     */
    public function lockedUntil(array $subjects, int $now): ?int
    {
        $latest = null;

        foreach ($subjects as $subject) {
            $until = $this->rows[$this->id($subject)]['locked_until'] ?? null;

            if ($until !== null && $until > $now && ($latest === null || $until > $latest)) {
                $latest = $until;
            }
        }

        return $latest;
    }

    /**
     * {@inheritDoc}
     */
    public function recordFailure(Subject $subject, int $now, int $windowExpiresAt): AttemptState
    {
        $id = $this->id($subject);
        $row = $this->rows[$id] ?? null;

        if ($row === null || $row['window_expires_at'] < $now) {
            $row = [
                'subject' => $subject,
                'failures' => 0,
                'lockouts' => 0,
                'locked_until' => $row['locked_until'] ?? null,
                'window_expires_at' => $windowExpiresAt,
            ];
        }

        $row['failures']++;
        $row['window_expires_at'] = $windowExpiresAt;
        $this->rows[$id] = $row;

        return new AttemptState($row['failures'], $row['lockouts'], $row['locked_until'], $row['window_expires_at']);
    }

    /**
     * {@inheritDoc}
     */
    public function lock(Subject $subject, int $lockedUntil, int $threshold, bool $long): bool
    {
        $id = $this->id($subject);

        if (! isset($this->rows[$id]) || $this->rows[$id]['failures'] < $threshold) {
            return false;
        }

        $this->rows[$id]['failures'] = 0;
        $this->rows[$id]['lockouts'] = $long ? 0 : $this->rows[$id]['lockouts'] + 1;
        $this->rows[$id]['locked_until'] = $lockedUntil;

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(array $subjects): void
    {
        foreach ($subjects as $subject) {
            unset($this->rows[$this->id($subject)]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function purgeExpired(int $now, int $limit): int
    {
        $deleted = 0;

        foreach ($this->rows as $id => $row) {
            if ($deleted >= $limit) {
                break;
            }

            if ($row['window_expires_at'] < $now && ($row['locked_until'] === null || $row['locked_until'] <= $now)) {
                unset($this->rows[$id]);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function activeLockouts(int $now, int $limit): array
    {
        $active = array_filter(
            $this->rows,
            static fn (array $row): bool => $row['locked_until'] !== null && $row['locked_until'] > $now,
        );

        usort($active, static fn (array $a, array $b): int => $b['locked_until'] <=> $a['locked_until']);

        return array_map(
            static fn (array $row): Lockout => new Lockout($row['subject'], (int) $row['locked_until'], false),
            array_slice($active, 0, $limit),
        );
    }

    /**
     * Number of counters held, for assertions on purging.
     */
    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * Storage key of a subject.
     *
     * @param  Subject  $subject  Subject.
     */
    private function id(Subject $subject): string
    {
        return $subject->scope()->value.':'.$subject->hexKey();
    }
}
