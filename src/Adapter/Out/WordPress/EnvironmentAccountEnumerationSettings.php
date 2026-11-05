<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\Out\WordPress;

use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;
use Pollora\Portcullis\Port\Out\AccountEnumerationSettingsPort;

final class EnvironmentAccountEnumerationSettings implements AccountEnumerationSettingsPort
{
    public const HIDE_ACCOUNTS = 'PORTCULLIS_HIDE_ACCOUNTS';

    /**
     * `false` closes the author archives.
     */
    public const AUTHOR_ARCHIVES = 'PORTCULLIS_AUTHOR_ARCHIVES';

    public function __construct(private readonly EnvironmentReader $reader = new EnvironmentReader) {}

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return $this->reader->bool(self::HIDE_ACCOUNTS, true);
    }

    /**
     * {@inheritDoc}
     */
    public function authorArchivePolicy(): AuthorArchivePolicy
    {
        $archivesArePublic = $this->reader->bool(self::AUTHOR_ARCHIVES, true);

        if (! $archivesArePublic) {
            return AuthorArchivePolicy::Closed;
        }

        if ($this->isEnabled()) {
            return AuthorArchivePolicy::PublicWithPosts;
        }

        return AuthorArchivePolicy::Untouched;
    }
}
