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
    public function __construct(private readonly HookRegistrarPort $hooks) {}

    public function register(): void
    {
        $this->hooks->addFilter('author_link', [$this, 'filterAuthorLink'], PHP_INT_MAX, 2);
    }

    /**
     * @internal Hooked on `author_link`; not part of the public API.
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
