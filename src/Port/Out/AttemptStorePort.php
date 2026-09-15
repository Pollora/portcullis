<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Port\Out;

use Pollora\Portcullis\Domain\Model\AttemptState;
use Pollora\Portcullis\Domain\Model\Lockout;
use Pollora\Portcullis\Domain\Model\Subject;

/**
 * Persists failure counters and lockouts.
 *
 * Implementations own atomicity and nothing else: every decision — thresholds,
 * durations, which tier applies — is taken by the Application layer from the
 * values they return. That is what lets a MySQL table and a Redis instance be
 * swapped without the protection behaving any differently.
 *
 * Concurrency is the whole difficulty. A brute-force attack is, by definition,
 * many simultaneous requests on the same subject; a read-modify-write sequence
 * would lose increments and let the attack run past its threshold. Hence the
 * contract below: increments are atomic, and a lockout is only applied if the
 * threshold is still reached at the moment it is written.
 */
interface AttemptStorePort
{
    /**
     * The latest end of lockout among the subjects, if any of them is locked out.
     *
     * @param  list<Subject>  $subjects  Subjects to look up.
     * @param  int  $now  Current timestamp; lockouts ending at or before it are ignored.
     * @return int|null Timestamp the longest active lockout ends at, `null` when none is active.
     */
    public function lockedUntil(array $subjects, int $now): ?int;

    /**
     * Records one failure, atomically.
     *
     * When the counter's window has already expired, it starts over: failures
     * back to 1 and lockouts back to 0. In every case the window is pushed to
     * `$windowExpiresAt`.
     *
     * @param  Subject  $subject  Subject the failure is attributed to.
     * @param  int  $now  Current timestamp.
     * @param  int  $windowExpiresAt  Timestamp the counter is forgotten at, barring a new failure.
     * @return AttemptState The counter as it stands after the increment.
     */
    public function recordFailure(Subject $subject, int $now, int $windowExpiresAt): AttemptState;

    /**
     * Locks the subject out, provided its failures still reach the threshold.
     *
     * On success the failures go back to 0, and the lockouts are either
     * incremented or, for a long lockout, reset to 0 so that the next cycle
     * starts with regular lockouts again.
     *
     * The condition is what makes concurrent failures safe: when several
     * requests cross the threshold at once, only the first one applies the
     * lockout — the others find the failures already reset and return `false`.
     *
     * @param  Subject  $subject  Subject to lock out.
     * @param  int  $lockedUntil  Timestamp the lockout ends at.
     * @param  int  $threshold  Failures the subject must still have for the lockout to apply.
     * @param  bool  $long  Whether this is a long lockout.
     * @return bool Whether this call applied the lockout.
     */
    public function lock(Subject $subject, int $lockedUntil, int $threshold, bool $long): bool;

    /**
     * Forgets everything about the subjects: failures, lockouts, current lockout.
     *
     * @param  list<Subject>  $subjects  Subjects to forget.
     */
    public function clear(array $subjects): void;

    /**
     * Deletes counters that are neither within their window nor locked out.
     *
     * @param  int  $now  Current timestamp.
     * @param  int  $limit  Maximum number of counters to delete in one call.
     * @return int Number of counters deleted.
     */
    public function purgeExpired(int $now, int $limit): int;

    /**
     * Lockouts still running, the longest first.
     *
     * @param  int  $now  Current timestamp.
     * @param  int  $limit  Maximum number of lockouts to return.
     * @return list<Lockout>
     */
    public function activeLockouts(int $now, int $limit): array;
}
