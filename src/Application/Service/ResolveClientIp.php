<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\IpRange;

/**
 * Works out which address a request really comes from.
 *
 * Getting this wrong defeats the whole protection, in one of two ways:
 *
 * - ignoring `X-Forwarded-For` behind a reverse proxy attributes every visitor to
 *   the proxy, and the first attacker locks the whole site out;
 * - believing it unconditionally lets an attacker send a different made-up
 *   address with every request, and never be locked out at all.
 *
 * Limit Login Attempts Reloaded falls into the second trap: it takes the
 * *leftmost* valid address of the header, which is precisely the part the client
 * writes. Proxies only ever *append* to the header, so the trustworthy reading
 * goes the other way: start from the peer address, and walk the header from
 * right to left for as long as each hop is a proxy the operator trusts. The
 * first hop that is not is the client.
 */
final class ResolveClientIp
{
    /**
     * Resolves the client address.
     *
     * @param  string  $remoteAddress  The TCP peer, `REMOTE_ADDR`.
     * @param  string|null  $forwardedFor  The raw `X-Forwarded-For` header, `null` when absent.
     * @param  list<IpRange>  $trustedProxies  Proxies whose header is believed.
     * @return ClientIp|null `null` only when the peer address itself is unusable.
     */
    public function resolve(string $remoteAddress, ?string $forwardedFor, array $trustedProxies): ?ClientIp
    {
        $client = ClientIp::tryFromString($remoteAddress);

        if ($client === null || $forwardedFor === null || ! $this->isTrusted($client, $trustedProxies)) {
            return $client;
        }

        $hops = array_reverse(explode(',', $forwardedFor));

        foreach ($hops as $hop) {
            $address = ClientIp::tryFromString($this->withoutPort($hop));

            if ($address === null) {
                // A hop a trusted proxy would never have written: the header was
                // tampered with before that proxy. The last address known to be
                // genuine is the best answer left.
                return $client;
            }

            $client = $address;

            if (! $this->isTrusted($address, $trustedProxies)) {
                return $address;
            }
        }

        return $client;
    }

    /**
     * Whether the address belongs to a trusted proxy.
     *
     * @param  ClientIp  $ip  Address to test.
     * @param  list<IpRange>  $trustedProxies  Trusted proxies.
     */
    private function isTrusted(ClientIp $ip, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $range) {
            if ($range->contains($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strips the port some proxies append to a hop.
     *
     * Handles `203.0.113.7:4711` and `[2001:db8::1]:4711`. A bare IPv6 address is
     * left alone: its colons are not a port separator.
     *
     * @param  string  $hop  One entry of the header.
     */
    private function withoutPort(string $hop): string
    {
        $hop = trim($hop);

        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $hop, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $hop, $matches) === 1) {
            return $matches[1];
        }

        return $hop;
    }
}
