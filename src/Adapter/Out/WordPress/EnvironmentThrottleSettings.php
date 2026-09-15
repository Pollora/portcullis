<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\Out\WordPress;

use Pollora\Portcullis\Application\Service\DeriveSubjects;
use Pollora\Portcullis\Domain\Model\IpRange;
use Pollora\Portcullis\Domain\Model\ScopePolicy;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;
use Pollora\Portcullis\Domain\Model\ThrottleSettings;
use Pollora\Portcullis\Port\Out\ThrottleSettingsPort;

/**
 * Reads the brute-force protection settings from constants and environment variables.
 *
 * Every key is optional; the defaults are those of Limit Login Attempts
 * Reloaded, plus a per-account threshold.
 */
final class EnvironmentThrottleSettings implements ThrottleSettingsPort
{
    public const ENABLED = 'PORTCULLIS_THROTTLE_ENABLED';

    public const MAX_RETRIES = 'PORTCULLIS_MAX_RETRIES';

    public const LOCKOUT_DURATION = 'PORTCULLIS_LOCKOUT_DURATION';

    public const MAX_LOCKOUTS = 'PORTCULLIS_MAX_LOCKOUTS';

    public const LONG_LOCKOUT_DURATION = 'PORTCULLIS_LONG_LOCKOUT_DURATION';

    public const RETRIES_VALIDITY = 'PORTCULLIS_RETRIES_VALIDITY';

    /**
     * `0` counts per address only.
     */
    public const ACCOUNT_MAX_RETRIES = 'PORTCULLIS_ACCOUNT_MAX_RETRIES';

    public const ACCOUNT_LOCKOUT_DURATION = 'PORTCULLIS_ACCOUNT_LOCKOUT_DURATION';

    public const TRUSTED_PROXIES = 'PORTCULLIS_TRUSTED_PROXIES';

    public const ALLOWLIST = 'PORTCULLIS_ALLOWLIST';

    public const SECRET = 'PORTCULLIS_SECRET';

    public const GENERIC_LOGIN_ERRORS = 'PORTCULLIS_GENERIC_LOGIN_ERRORS';

    /**
     * WordPress keys the secret is derived from when none is configured.
     *
     * Every installation defines them in `wp-config.php`, they are secret by
     * construction, and they differ between environments — exactly the
     * properties the subject secret needs. Rotating them resets the counters,
     * which is harmless.
     *
     * @var list<string>
     */
    private const FALLBACK_SECRET_CONSTANTS = ['AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT'];

    /**
     * @param  EnvironmentReader  $reader  Constant and environment lookup.
     */
    public function __construct(private readonly EnvironmentReader $reader = new EnvironmentReader) {}

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return $this->reader->bool(self::ENABLED, true);
    }

    /**
     * {@inheritDoc}
     */
    public function settings(): ThrottleSettings
    {
        $lockoutDuration = $this->reader->int(self::LOCKOUT_DURATION, ThrottlePolicy::DEFAULT_LOCKOUT_DURATION);
        $maxLockouts = $this->reader->int(self::MAX_LOCKOUTS, ThrottlePolicy::DEFAULT_MAX_LOCKOUTS);
        $longLockoutDuration = $this->reader->int(self::LONG_LOCKOUT_DURATION, ThrottlePolicy::DEFAULT_LONG_LOCKOUT_DURATION);
        $accountMaxRetries = $this->reader->int(self::ACCOUNT_MAX_RETRIES, ThrottlePolicy::DEFAULT_ACCOUNT_MAX_RETRIES);

        $policy = new ThrottlePolicy(
            $this->reader->int(self::RETRIES_VALIDITY, ThrottlePolicy::DEFAULT_RETRIES_VALIDITY),
            new ScopePolicy(
                $this->reader->int(self::MAX_RETRIES, ThrottlePolicy::DEFAULT_MAX_RETRIES),
                $lockoutDuration,
                $maxLockouts,
                $longLockoutDuration,
            ),
            $accountMaxRetries === 0 ? null : new ScopePolicy(
                $accountMaxRetries,
                $this->reader->int(self::ACCOUNT_LOCKOUT_DURATION, $lockoutDuration),
                $maxLockouts,
                $longLockoutDuration,
            ),
        );

        return new ThrottleSettings(
            $policy,
            IpRange::listFromString($this->reader->string(self::TRUSTED_PROXIES) ?? ''),
            IpRange::listFromString($this->reader->string(self::ALLOWLIST) ?? ''),
            $this->secret(),
            $this->reader->bool(self::GENERIC_LOGIN_ERRORS, true),
        );
    }

    /**
     * A secret derived from the WordPress keys, for when none is configured.
     *
     * Public so that the composition root can still derive subjects when the
     * rest of the configuration is rejected. The value goes through SHA-256 so
     * that it always reaches {@see DeriveSubjects::MINIMUM_SECRET_LENGTH}, and is
     * prefixed so that it never equals a key WordPress uses for something else.
     */
    public static function derivedSecret(): string
    {
        $material = '';

        foreach (self::FALLBACK_SECRET_CONSTANTS as $constant) {
            $value = defined($constant) ? constant($constant) : null;
            $material .= is_string($value) ? $value : '';
        }

        return hash('sha256', 'portcullis-subjects|'.$material);
    }

    /**
     * The configured secret, or one derived from the WordPress keys.
     */
    private function secret(): string
    {
        return $this->reader->string(self::SECRET) ?? self::derivedSecret();
    }
}
