<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;
use Pollora\Portcullis\Domain\Model\AuthorArchiveUrl;

/**
 * Decides whether an author archive answers a 404, for a given policy.
 */
final class ConcealAuthorArchive
{
    /**
     * @param  AuthorArchivePolicy  $policy  How author archives are treated.
     */
    public function __construct(private readonly AuthorArchivePolicy $policy) {}

    /**
     * Never with `Untouched`. Otherwise always for the `?author=<id>` shortcut,
     * whose redirect would spell the slug out, and for every archive with `Closed`.
     * With `PublicWithPosts`, only an archive that lists no post.
     *
     * @param  AuthorArchiveUrl  $url  Kind of URL the archive was requested through.
     * @param  int  $listedPosts  Number of posts the archive lists.
     * @return bool `true` when the archive must answer a 404.
     */
    public function conceals(AuthorArchiveUrl $url, int $listedPosts): bool
    {
        if ($this->policy === AuthorArchivePolicy::Untouched) {
            return false;
        }

        if ($url === AuthorArchiveUrl::Shortcut) {
            return true;
        }

        if ($this->policy === AuthorArchivePolicy::Closed) {
            return true;
        }

        return $listedPosts === 0;
    }
}
