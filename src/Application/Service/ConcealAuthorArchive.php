<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;
use Pollora\Portcullis\Domain\Model\AuthorArchiveUrl;

final class ConcealAuthorArchive
{
    public function __construct(private readonly AuthorArchivePolicy $policy) {}

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
