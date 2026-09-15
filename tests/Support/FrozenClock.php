<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Tests\Support;

use Pollora\Portcullis\Port\Out\ClockPort;

/**
 * Test double for a clock that only moves when told to.
 */
final class FrozenClock implements ClockPort
{
    /**
     * @param  int  $now  Initial timestamp.
     */
    public function __construct(private int $now = 1_700_000_000) {}

    /**
     * {@inheritDoc}
     */
    public function now(): int
    {
        return $this->now;
    }

    /**
     * Moves the clock forward.
     *
     * @param  int  $seconds  Seconds to advance by.
     */
    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
