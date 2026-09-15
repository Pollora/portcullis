<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Model;

use Pollora\Portcullis\Domain\Exception\InvalidThrottlePolicyException;

/**
 * How failed logins are counted and punished, per scope.
 */
final class ThrottlePolicy
{
    /**
     * Failures before a lockout, per address. Limit Login Attempts Reloaded's default.
     */
    public const DEFAULT_MAX_RETRIES = 4;

    /**
     * Seconds of a regular lockout: 20 minutes.
     */
    public const DEFAULT_LOCKOUT_DURATION = 1200;

    /**
     * Regular lockouts after which the long one applies.
     */
    public const DEFAULT_MAX_LOCKOUTS = 4;

    /**
     * Seconds of a long lockout: 24 hours.
     */
    public const DEFAULT_LONG_LOCKOUT_DURATION = 86400;

    /**
     * Seconds a counter survives without a new failure: 24 hours.
     */
    public const DEFAULT_RETRIES_VALIDITY = 86400;

    /**
     * Failures before a lockout, per account, all addresses combined.
     *
     * Deliberately higher than the per-address threshold: this scope exists to
     * catch distributed attacks, and a lower value would let anyone lock a
     * colleague out by mistyping their login a few times.
     */
    public const DEFAULT_ACCOUNT_MAX_RETRIES = 20;

    /**
     * @param  int  $retriesValidity  Seconds a counter survives without a new failure.
     * @param  ScopePolicy  $ip  Thresholds per address.
     * @param  ScopePolicy|null  $account  Thresholds per account, `null` to count per address only.
     *
     * @throws InvalidThrottlePolicyException When the validity window is shorter than a lockout.
     */
    public function __construct(
        private readonly int $retriesValidity,
        private readonly ScopePolicy $ip,
        private readonly ?ScopePolicy $account,
    ) {
        foreach ([$ip, $account] as $scope) {
            if ($scope !== null && $retriesValidity < $scope->lockoutDuration()) {
                // A counter expiring while its subject is still locked out would
                // hand the attacker a fresh set of retries on release.
                throw InvalidThrottlePolicyException::validityShorterThanLockout($retriesValidity, $scope->lockoutDuration());
            }
        }
    }

    /**
     * The policy used when nothing is configured.
     */
    public static function defaults(): self
    {
        return new self(
            self::DEFAULT_RETRIES_VALIDITY,
            new ScopePolicy(
                self::DEFAULT_MAX_RETRIES,
                self::DEFAULT_LOCKOUT_DURATION,
                self::DEFAULT_MAX_LOCKOUTS,
                self::DEFAULT_LONG_LOCKOUT_DURATION,
            ),
            new ScopePolicy(
                self::DEFAULT_ACCOUNT_MAX_RETRIES,
                self::DEFAULT_LOCKOUT_DURATION,
                self::DEFAULT_MAX_LOCKOUTS,
                self::DEFAULT_LONG_LOCKOUT_DURATION,
            ),
        );
    }

    /**
     * Seconds a counter survives without a new failure.
     */
    public function retriesValidity(): int
    {
        return $this->retriesValidity;
    }

    /**
     * Thresholds for a scope, or `null` when that scope is not counted.
     *
     * @param  SubjectScope  $scope  Scope to look up.
     */
    public function forScope(SubjectScope $scope): ?ScopePolicy
    {
        return match ($scope) {
            SubjectScope::Ip => $this->ip,
            SubjectScope::Account => $this->account,
        };
    }
}
