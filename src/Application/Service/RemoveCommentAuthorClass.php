<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

/**
 * Removes the `comment-author-<slug>` class that `get_comment_class()` adds for
 * registered users. The other classes stay, `byuser` and `bypostauthor` included.
 */
final class RemoveCommentAuthorClass
{
    /**
     * Drops every occurrence of the author class and reindexes the list.
     *
     * @param  array<mixed>  $classes  The comment's CSS classes.
     * @param  string  $authorClass  The class to drop, built from the author's slug.
     * @return list<mixed> The classes without the author class.
     */
    public function remove(array $classes, string $authorClass): array
    {
        $kept = array_filter($classes, static fn (mixed $class): bool => $class !== $authorClass);

        return array_values($kept);
    }
}
