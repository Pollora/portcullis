<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

/**
 * Added by `get_comment_class()` for registered users.
 */
final class RemoveCommentAuthorClass
{
    /**
     * @param  array<mixed>  $classes
     * @return list<mixed>
     */
    public function remove(array $classes, string $authorClass): array
    {
        $kept = array_filter($classes, static fn (mixed $class): bool => $class !== $authorClass);

        return array_values($kept);
    }
}
