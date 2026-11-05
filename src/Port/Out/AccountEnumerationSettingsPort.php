<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Port\Out;

use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;

/**
 * Where the account enumeration settings come from.
 */
interface AccountEnumerationSettingsPort
{
    /**
     * Whether the account enumeration protection is active. Enabled by default.
     */
    public function isEnabled(): bool;

    /**
     * How author archives are treated. Never `Closed` by default: closing the archives changes public URLs.
     */
    public function authorArchivePolicy(): AuthorArchivePolicy;
}
