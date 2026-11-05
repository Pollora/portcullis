<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

/**
 * Removes the author fields from an oEmbed response. `author_url` is the author
 * archive, `author_name` spells the display name out, and both fields are
 * optional in oEmbed.
 */
final class RemoveOembedAuthor
{
    /**
     * @var list<string>
     */
    private const AUTHOR_FIELDS = ['author_name', 'author_url'];

    /**
     * Drops `author_name` and `author_url` from the response data.
     *
     * @param  array<string, mixed>  $data  The oEmbed response data.
     * @return array<string, mixed> The same data without the author fields.
     */
    public function remove(array $data): array
    {
        return array_diff_key($data, array_flip(self::AUTHOR_FIELDS));
    }
}
