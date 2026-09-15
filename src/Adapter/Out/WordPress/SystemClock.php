<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\Out\WordPress;

use Pollora\Portcullis\Port\Out\ClockPort;

/**
 * The clock of the machine running PHP.
 */
final class SystemClock implements ClockPort
{
    /**
     * {@inheritDoc}
     */
    public function now(): int
    {
        return time();
    }
}
