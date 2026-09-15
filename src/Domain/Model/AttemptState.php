<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Model;

/**
 * A failure counter, as it stands right after a failure was recorded.
 */
final class AttemptState
{
    /**
     * @param  int  $failures  Failures since the last lockout, or since the window opened.
     * @param  int  $lockouts  Regular lockouts served within the validity window.
     * @param  int|null  $lockedUntil  Timestamp the current lockout ends at, `null` when not locked out.
     * @param  int  $windowExpiresAt  Timestamp the counter is forgotten at, barring a new failure.
     */
    public function __construct(
        private readonly int $failures,
        private readonly int $lockouts,
        private readonly ?int $lockedUntil,
        private readonly int $windowExpiresAt,
    ) {}

    /**
     * Failures since the last lockout, or since the window opened.
     */
    public function failures(): int
    {
        return $this->failures;
    }

    /**
     * Regular lockouts served within the validity window.
     */
    public function lockouts(): int
    {
        return $this->lockouts;
    }

    /**
     * Timestamp the current lockout ends at, `null` when not locked out.
     */
    public function lockedUntil(): ?int
    {
        return $this->lockedUntil;
    }

    /**
     * Timestamp the counter is forgotten at, barring a new failure.
     */
    public function windowExpiresAt(): int
    {
        return $this->windowExpiresAt;
    }
}
