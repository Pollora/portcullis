<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Model;

use Stringable;

/**
 * The network address a login attempt is attributed to.
 *
 * Built only from a syntactically valid IPv4 or IPv6 address, so that a value
 * read from a header an attacker controls can never turn into a key of its own
 * choosing.
 */
final class ClientIp implements Stringable
{
    /**
     * Prefix length IPv6 addresses are grouped on.
     *
     * A single subscriber is routinely handed a whole /64, and rotating through
     * it costs nothing. Counting failures per address would let an attacker
     * start from zero with every request; counting them per /64 does not.
     */
    public const IPV6_PREFIX_LENGTH = 64;

    /**
     * @param  string  $value  The address, in the canonical form `inet_ntop()` produces.
     * @param  string  $packed  The address as returned by `inet_pton()`.
     */
    private function __construct(
        private readonly string $value,
        private readonly string $packed,
    ) {}

    /**
     * Builds an address from a raw value, or returns `null` when it is not one.
     *
     * Surrounding whitespace is ignored. Anything else — a port, brackets, a
     * zone index — makes the value invalid: stripping those is the job of the
     * code reading the header, which knows the format it is dealing with.
     *
     * @param  string  $value  Raw address.
     */
    public static function tryFromString(string $value): ?self
    {
        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($value);

        if ($packed === false) {
            return null;
        }

        $canonical = inet_ntop($packed);

        return new self($canonical === false ? $value : $canonical, $packed);
    }

    /**
     * The address, in canonical form.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * The address in binary form: 4 bytes for IPv4, 16 for IPv6.
     */
    public function packed(): string
    {
        return $this->packed;
    }

    /**
     * Whether this is an IPv6 address.
     */
    public function isIpv6(): bool
    {
        return strlen($this->packed) === 16;
    }

    /**
     * The group failures are counted on.
     *
     * The address itself for IPv4, its /64 network for IPv6 — see
     * {@see self::IPV6_PREFIX_LENGTH}.
     */
    public function bucket(): string
    {
        if (! $this->isIpv6()) {
            return $this->value;
        }

        $bytes = intdiv(self::IPV6_PREFIX_LENGTH, 8);
        $network = substr($this->packed, 0, $bytes).str_repeat("\0", 16 - $bytes);
        $address = inet_ntop($network);

        return ($address === false ? $this->value : $address).'/'.self::IPV6_PREFIX_LENGTH;
    }

    /**
     * {@inheritDoc}
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
