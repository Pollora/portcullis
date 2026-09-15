<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Model;

/**
 * A subject that is, or has just been, locked out.
 */
final class Lockout
{
    /**
     * @param  Subject  $subject  What is locked out.
     * @param  int  $lockedUntil  Timestamp the lockout ends at.
     * @param  bool  $long  Whether this is a long lockout.
     */
    public function __construct(
        private readonly Subject $subject,
        private readonly int $lockedUntil,
        private readonly bool $long,
    ) {}

    /**
     * What is locked out.
     */
    public function subject(): Subject
    {
        return $this->subject;
    }

    /**
     * Timestamp the lockout ends at.
     */
    public function lockedUntil(): int
    {
        return $this->lockedUntil;
    }

    /**
     * Whether this is a long lockout.
     *
     * Only known for a lockout that has just been decided; a lockout read back
     * from storage reports `false`, the store keeping no record of the tier.
     */
    public function isLong(): bool
    {
        return $this->long;
    }

    /**
     * Seconds left before the lockout ends, never negative.
     *
     * @param  int  $now  Current timestamp.
     */
    public function secondsLeft(int $now): int
    {
        return max(0, $this->lockedUntil - $now);
    }
}
