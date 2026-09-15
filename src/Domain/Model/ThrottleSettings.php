<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Model;

/**
 * Everything the brute-force protection needs to know about its configuration.
 */
final class ThrottleSettings
{
    /**
     * @param  ThrottlePolicy  $policy  Thresholds and durations.
     * @param  list<IpRange>  $trustedProxies  Proxies whose `X-Forwarded-For` header is believed.
     * @param  list<IpRange>  $allowlist  Addresses that are never locked out.
     * @param  string  $secret  Key the stored subjects are derived with.
     * @param  bool  $genericErrors  Whether login errors stop telling unknown accounts from wrong passwords.
     */
    public function __construct(
        private readonly ThrottlePolicy $policy,
        private readonly array $trustedProxies,
        private readonly array $allowlist,
        private readonly string $secret,
        private readonly bool $genericErrors,
    ) {}

    /**
     * Thresholds and durations.
     */
    public function policy(): ThrottlePolicy
    {
        return $this->policy;
    }

    /**
     * Proxies whose `X-Forwarded-For` header is believed.
     *
     * @return list<IpRange>
     */
    public function trustedProxies(): array
    {
        return $this->trustedProxies;
    }

    /**
     * Addresses that are never locked out.
     *
     * @return list<IpRange>
     */
    public function allowlist(): array
    {
        return $this->allowlist;
    }

    /**
     * Key the stored subjects are derived with.
     */
    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * Whether login errors stop telling unknown accounts from wrong passwords.
     */
    public function genericErrors(): bool
    {
        return $this->genericErrors;
    }

    /**
     * Whether the address is on the allowlist.
     *
     * @param  ClientIp  $ip  Address to test.
     */
    public function isAllowlisted(ClientIp $ip): bool
    {
        foreach ($this->allowlist as $range) {
            if ($range->contains($ip)) {
                return true;
            }
        }

        return false;
    }
}
