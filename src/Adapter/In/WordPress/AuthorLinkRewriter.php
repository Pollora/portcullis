<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\In\WordPress;

use Pollora\Portcullis\Port\Out\HookRegistrarPort;

/**
 * Author links go with the archives: they would otherwise point to a 404 whose
 * URL spells the slug out.
 */
final class AuthorLinkRewriter
{
    /**
     * @param  HookRegistrarPort  $hooks  Hook system of the host.
     */
    public function __construct(private readonly HookRegistrarPort $hooks) {}

    /**
     * Hooks `author_link`, at `PHP_INT_MAX`.
     */
    public function register(): void
    {
        $this->hooks->addFilter('author_link', [$this, 'filterAuthorLink'], PHP_INT_MAX, 2);
    }

    /**
     * Points an author link to the home URL, or to what the `portcullis/author_link`
     * filter returns: it receives the home URL and the author's identifier. An
     * answer that is not a string falls back to the home URL.
     *
     * @internal Hooked on `author_link`; not part of the public API.
     *
     * @param  mixed  $link  The author archive URL, never used: it spells the slug out.
     * @param  mixed  $authorId  Identifier of the author.
     */
    public function filterAuthorLink(mixed $link, mixed $authorId): string
    {
        $home = home_url('/');

        // The slug is withheld on purpose: the link must not spell it out.
        $replacement = $this->hooks->applyFilters('portcullis/author_link', $home, (int) $authorId);

        if (! is_string($replacement)) {
            return $home;
        }

        return $replacement;
    }
}
