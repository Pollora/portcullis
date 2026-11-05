<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Port\Out;

use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;

interface AccountEnumerationSettingsPort
{
    /**
     * Enabled by default.
     */
    public function isEnabled(): bool;

    /**
     * Never `Closed` by default: closing the archives changes public URLs.
     */
    public function authorArchivePolicy(): AuthorArchivePolicy;
}
