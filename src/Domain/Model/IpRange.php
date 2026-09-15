<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Model;

use Pollora\Portcullis\Domain\Exception\InvalidIpRangeException;
use Stringable;

/**
 * A network in CIDR notation, or a single address.
 *
 * Used for the two lists an operator configures: the proxies whose forwarding
 * headers are believed, and the addresses that are never locked out.
 */
final class IpRange implements Stringable
{
    /**
     * @param  string  $network  The network address, packed.
     * @param  int  $prefixLength  Number of leading bits that must match.
     * @param  string  $notation  The range as configured, normalised.
     */
    private function __construct(
        private readonly string $network,
        private readonly int $prefixLength,
        private readonly string $notation,
    ) {}

    /**
     * Builds a range from `203.0.113.0/24`, `2001:db8::/32` or a bare address.
     *
     * @param  string  $value  Raw range.
     *
     * @throws InvalidIpRangeException When the value is not a valid address or network.
     */
    public static function fromString(string $value): self
    {
        $value = trim($value);
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        $ip = ClientIp::tryFromString((string) $address);

        if ($ip === null) {
            throw InvalidIpRangeException::malformed($value);
        }

        $maximum = strlen($ip->packed()) * 8;

        if ($prefix === null) {
            $length = $maximum;
        } elseif (preg_match('/^\d{1,3}$/', $prefix) === 1 && (int) $prefix <= $maximum) {
            $length = (int) $prefix;
        } else {
            throw InvalidIpRangeException::malformed($value);
        }

        return new self(
            self::mask($ip->packed(), $length),
            $length,
            $prefix === null ? $ip->value() : $ip->value().'/'.$length,
        );
    }

    /**
     * Parses a comma- or whitespace-separated list, as found in an environment variable.
     *
     * @param  string  $value  Raw list.
     * @return list<self>
     *
     * @throws InvalidIpRangeException When any entry is invalid.
     */
    public static function listFromString(string $value): array
    {
        $entries = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        return array_map(self::fromString(...), $entries === false ? [] : $entries);
    }

    /**
     * Whether the address belongs to this range.
     *
     * An IPv4 address never matches an IPv6 range and vice versa, including
     * IPv4-mapped IPv6 addresses: configure both forms if a proxy speaks both.
     *
     * @param  ClientIp  $ip  Address to test.
     */
    public function contains(ClientIp $ip): bool
    {
        if (strlen($ip->packed()) !== strlen($this->network)) {
            return false;
        }

        return self::mask($ip->packed(), $this->prefixLength) === $this->network;
    }

    /**
     * {@inheritDoc}
     */
    public function __toString(): string
    {
        return $this->notation;
    }

    /**
     * Zeroes every bit past the prefix.
     *
     * @param  string  $packed  Packed address.
     * @param  int  $prefixLength  Number of bits to keep.
     */
    private static function mask(string $packed, int $prefixLength): string
    {
        $bytes = strlen($packed);
        $masked = '';

        for ($i = 0; $i < $bytes; $i++) {
            $bits = max(0, min(8, $prefixLength - $i * 8));
            $masked .= chr(ord($packed[$i]) & ((0xFF << (8 - $bits)) & 0xFF));
        }

        return $masked;
    }
}
