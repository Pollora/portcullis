<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Tests\Support;

use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;
use Pollora\Portcullis\Port\Out\AccountEnumerationSettingsPort;

/**
 * Account enumeration settings held in memory.
 */
final class FixedEnumerationSettings implements AccountEnumerationSettingsPort
{
    public function __construct(
        private readonly bool $enabled = true,
        private readonly AuthorArchivePolicy $policy = AuthorArchivePolicy::PublicWithPosts,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function authorArchivePolicy(): AuthorArchivePolicy
    {
        return $this->policy;
    }
}
