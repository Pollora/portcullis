<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\Out\WordPress;

use Pollora\Portcullis\Port\Out\ClientAddressPort;

/**
 * Reads the client address facts from `$_SERVER`.
 */
final class SuperglobalClientAddress implements ClientAddressPort
{
    /**
     * {@inheritDoc}
     */
    public function remoteAddress(): string
    {
        $address = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($address) ? $address : '';
    }

    /**
     * {@inheritDoc}
     */
    public function forwardedFor(): ?string
    {
        $header = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        return is_string($header) && $header !== '' ? $header : null;
    }
}
