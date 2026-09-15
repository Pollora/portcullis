<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Port\Out;

/**
 * The network-level facts a client address is resolved from.
 *
 * Raw values only: deciding which of them to believe is the job of the
 * ResolveClientIp application service.
 */
interface ClientAddressPort
{
    /**
     * The TCP peer address, empty when unknown.
     */
    public function remoteAddress(): string;

    /**
     * The raw `X-Forwarded-For` header, `null` when absent.
     */
    public function forwardedFor(): ?string;
}
