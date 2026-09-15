<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Exception;

use InvalidArgumentException;
use Pollora\Portcullis\Domain\Model\IpRange;

/**
 * Thrown when a configured address or network cannot be turned into an {@see IpRange}.
 */
final class InvalidIpRangeException extends InvalidArgumentException
{
    /**
     * The value is neither an address nor a network in CIDR notation.
     *
     * @param  string  $value  The offending value, as configured.
     */
    public static function malformed(string $value): self
    {
        return new self(sprintf(
            'The IP range "%s" is not valid: expected an address such as 203.0.113.7 or a network such as 203.0.113.0/24.',
            $value
        ));
    }
}
