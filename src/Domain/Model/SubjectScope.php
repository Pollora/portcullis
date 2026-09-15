<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Model;

/**
 * What a failure counter is attached to.
 *
 * The two scopes answer different attacks, which is why they are counted
 * independently:
 *
 * - {@see self::Ip} stops one source hammering the form, whatever accounts it
 *   tries.
 * - {@see self::Account} stops a botnet spreading its attempts on one account
 *   across thousands of addresses, each of which stays under the per-address
 *   threshold.
 *
 * Backed by an integer because the value is stored: it must stay stable across
 * releases, so cases may be added but never renumbered.
 */
enum SubjectScope: int
{
    /**
     * The client address, or its /64 for IPv6.
     */
    case Ip = 1;

    /**
     * The account targeted by the attempt.
     */
    case Account = 2;

    /**
     * Human-readable name, for logs and WP-CLI output.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ip => 'ip',
            self::Account => 'account',
        };
    }
}
