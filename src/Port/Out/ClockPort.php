<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Port\Out;

/**
 * Tells the time.
 *
 * Every lockout decision is a comparison between timestamps, so the clock is a
 * port: the tests freeze and advance it instead of sleeping.
 */
interface ClockPort
{
    /**
     * The current Unix timestamp.
     */
    public function now(): int;
}
