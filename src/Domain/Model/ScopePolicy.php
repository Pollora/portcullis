<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Model;

use Pollora\Portcullis\Domain\Exception\InvalidThrottlePolicyException;

/**
 * Lockout thresholds and durations for one {@see SubjectScope}.
 *
 * Two tiers, as in Limit Login Attempts Reloaded, whose defaults are reused:
 *
 * - every {@see self::maxRetries()} failures, the subject is locked out for
 *   {@see self::lockoutDuration()} seconds;
 * - the {@see self::maxLockouts()}-th lockout within the validity window lasts
 *   {@see self::longLockoutDuration()} seconds instead, and starts the count of
 *   lockouts over.
 *
 * A short lockout slows a human who mistyped down barely; a script going
 * through a wordlist ends up spending most of its time locked out for a day.
 */
final class ScopePolicy
{
    /**
     * @param  int  $maxRetries  Failures that trigger a lockout.
     * @param  int  $lockoutDuration  Seconds a regular lockout lasts.
     * @param  int  $maxLockouts  Lockouts after which the long duration applies. `0` disables the long tier.
     * @param  int  $longLockoutDuration  Seconds a long lockout lasts.
     *
     * @throws InvalidThrottlePolicyException When a value is out of range.
     */
    public function __construct(
        private readonly int $maxRetries,
        private readonly int $lockoutDuration,
        private readonly int $maxLockouts,
        private readonly int $longLockoutDuration,
    ) {
        if ($maxRetries < 1) {
            throw InvalidThrottlePolicyException::notPositive('max retries', $maxRetries);
        }

        if ($lockoutDuration < 1) {
            throw InvalidThrottlePolicyException::notPositive('lockout duration', $lockoutDuration);
        }

        if ($maxLockouts < 0) {
            throw InvalidThrottlePolicyException::negative('max lockouts', $maxLockouts);
        }

        if ($maxLockouts > 0 && $longLockoutDuration < $lockoutDuration) {
            throw InvalidThrottlePolicyException::longShorterThanRegular($longLockoutDuration, $lockoutDuration);
        }
    }

    /**
     * Failures that trigger a lockout.
     */
    public function maxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Seconds a regular lockout lasts.
     */
    public function lockoutDuration(): int
    {
        return $this->lockoutDuration;
    }

    /**
     * Lockouts after which the long duration applies, `0` when the long tier is off.
     */
    public function maxLockouts(): int
    {
        return $this->maxLockouts;
    }

    /**
     * Seconds a long lockout lasts.
     */
    public function longLockoutDuration(): int
    {
        return $this->longLockoutDuration;
    }

    /**
     * Whether the next lockout is a long one.
     *
     * @param  int  $previousLockouts  Lockouts already served within the validity window.
     */
    public function isLongLockout(int $previousLockouts): bool
    {
        return $this->maxLockouts > 0 && $previousLockouts + 1 >= $this->maxLockouts;
    }

    /**
     * Seconds the next lockout lasts.
     *
     * @param  int  $previousLockouts  Lockouts already served within the validity window.
     */
    public function durationFor(int $previousLockouts): int
    {
        return $this->isLongLockout($previousLockouts) ? $this->longLockoutDuration : $this->lockoutDuration;
    }
}
