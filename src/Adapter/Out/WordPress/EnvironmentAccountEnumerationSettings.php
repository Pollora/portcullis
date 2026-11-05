<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\Out\WordPress;

use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;
use Pollora\Portcullis\Port\Out\AccountEnumerationSettingsPort;

/**
 * Reads the account enumeration settings from the environment.
 *
 * `PORTCULLIS_HIDE_ACCOUNTS` switches the protection off with a falsy value, and
 * `PORTCULLIS_AUTHOR_ARCHIVES` closes the author archives with a falsy one.
 * Both are read by {@see EnvironmentReader}: an unset or unrecognised value keeps
 * the default, which is `true` for each.
 */
final class EnvironmentAccountEnumerationSettings implements AccountEnumerationSettingsPort
{
    /**
     * `false` switches the account enumeration protection off.
     */
    public const HIDE_ACCOUNTS = 'PORTCULLIS_HIDE_ACCOUNTS';

    /**
     * `false` closes the author archives.
     */
    public const AUTHOR_ARCHIVES = 'PORTCULLIS_AUTHOR_ARCHIVES';

    /**
     * @param  EnvironmentReader  $reader  Looks the variables up.
     */
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
     *
     * `Closed` when `PORTCULLIS_AUTHOR_ARCHIVES` is falsy, whatever the protection
     * does. Otherwise `PublicWithPosts` while the protection is enabled, and
     * `Untouched` once it is switched off.
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
