<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

/**
 * `author_url` is the author archive. Both fields are optional in oEmbed.
 */
final class RemoveOembedAuthor
{
    /**
     * @var list<string>
     */
    private const AUTHOR_FIELDS = ['author_name', 'author_url'];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function remove(array $data): array
    {
        return array_diff_key($data, array_flip(self::AUTHOR_FIELDS));
    }
}
