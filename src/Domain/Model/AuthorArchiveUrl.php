<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Model;

enum AuthorArchiveUrl
{
    /**
     * What `get_author_posts_url()` returns: `/author/<slug>`, or `?author=<id>`
     * without pretty permalinks.
     */
    case Canonical;

    /**
     * `?author=<id>` under pretty permalinks, which core redirects to the slug.
     */
    case Shortcut;
}
