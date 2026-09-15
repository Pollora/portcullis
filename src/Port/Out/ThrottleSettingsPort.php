<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Port\Out;

use Pollora\Portcullis\Domain\Exception\InvalidIpRangeException;
use Pollora\Portcullis\Domain\Exception\InvalidThrottlePolicyException;
use Pollora\Portcullis\Domain\Model\ThrottleSettings;

/**
 * Supplies the configuration of the brute-force protection.
 *
 * The default implementation reads constants and environment variables, for the
 * same reason the login slug is read from there: settings stored in the database
 * would travel with dumps, and an allowlist or a secret has no business being
 * restored on another environment.
 */
interface ThrottleSettingsPort
{
    /**
     * Whether brute-force protection is switched on. Enabled by default.
     */
    public function isEnabled(): bool;

    /**
     * The configured settings.
     *
     * @throws InvalidThrottlePolicyException When thresholds are configured but incoherent.
     * @throws InvalidIpRangeException When a proxy or allowlist entry is invalid.
     */
    public function settings(): ThrottleSettings;
}
