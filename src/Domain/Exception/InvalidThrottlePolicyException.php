<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Exception;

use InvalidArgumentException;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;

/**
 * Thrown when configured thresholds cannot form a coherent {@see ThrottlePolicy}.
 *
 * The composition root falls back to the defaults and reports the error rather
 * than letting it bubble up: a typo in a threshold must not take the site down,
 * nor switch brute-force protection off.
 */
final class InvalidThrottlePolicyException extends InvalidArgumentException
{
    /**
     * A value that must be at least 1 is not.
     *
     * @param  string  $name  Human-readable name of the setting.
     * @param  int  $value  The offending value.
     */
    public static function notPositive(string $name, int $value): self
    {
        return new self(sprintf('The %s must be at least 1, %d given.', $name, $value));
    }

    /**
     * A value that must not be negative is.
     *
     * @param  string  $name  Human-readable name of the setting.
     * @param  int  $value  The offending value.
     */
    public static function negative(string $name, int $value): self
    {
        return new self(sprintf('The %s cannot be negative, %d given.', $name, $value));
    }

    /**
     * The long lockout would be shorter than the regular one.
     *
     * @param  int  $long  Configured long lockout duration, in seconds.
     * @param  int  $regular  Configured regular lockout duration, in seconds.
     */
    public static function longShorterThanRegular(int $long, int $regular): self
    {
        return new self(sprintf(
            'The long lockout duration (%ds) cannot be shorter than the regular one (%ds).',
            $long,
            $regular
        ));
    }

    /**
     * Counters would expire while their subject is still locked out.
     *
     * @param  int  $validity  Configured validity window, in seconds.
     * @param  int  $lockout  Configured lockout duration, in seconds.
     */
    public static function validityShorterThanLockout(int $validity, int $lockout): self
    {
        return new self(sprintf(
            'The retries validity (%ds) cannot be shorter than the lockout duration (%ds).',
            $validity,
            $lockout
        ));
    }

    /**
     * A configured value is not an integer.
     *
     * @param  string  $key  Name of the configuration key.
     * @param  string  $value  The offending value, as configured.
     */
    public static function notAnInteger(string $key, string $value): self
    {
        return new self(sprintf('%s must be an integer, "%s" given.', $key, $value));
    }
}
