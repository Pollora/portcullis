<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\In\WordPress;

use Pollora\Portcullis\Application\Service\RemoveAccountRoutes;
use Pollora\Portcullis\Application\Service\RemoveCommentAuthorClass;
use Pollora\Portcullis\Application\Service\RemoveOembedAuthor;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;
use WP_Comment;

final class AccountEnumerationGuard
{
    /**
     * Needed by the block editor's author selector.
     */
    private const LISTING_CAPABILITY = 'edit_posts';

    private const USERS_SITEMAP_PROVIDER = 'users';

    private const COMMENT_AUTHOR_CLASS_PREFIX = 'comment-author-';

    public function __construct(
        private readonly RemoveAccountRoutes $accountRoutes,
        private readonly RemoveCommentAuthorClass $commentAuthorClass,
        private readonly RemoveOembedAuthor $oembedAuthor,
        private readonly HookRegistrarPort $hooks,
    ) {}

    public function register(): void
    {
        $this->hooks->addFilter('rest_endpoints', [$this, 'filterRoutes'], PHP_INT_MAX, 1);
        $this->hooks->addFilter('wp_sitemaps_add_provider', [$this, 'filterSitemapProvider'], PHP_INT_MAX, 2);
        $this->hooks->addFilter('oembed_response_data', [$this, 'filterOembedData'], PHP_INT_MAX, 1);
        $this->hooks->addFilter('comment_class', [$this, 'filterCommentClasses'], PHP_INT_MAX, 4);
    }

    /**
     * @internal Hooked on `rest_endpoints`; not part of the public API.
     */
    public function filterRoutes(mixed $routes): mixed
    {
        if (! is_array($routes)) {
            return $routes;
        }

        if ($this->canListAccounts()) {
            return $routes;
        }

        return $this->accountRoutes->remove($routes);
    }

    /**
     * @internal Hooked on `wp_sitemaps_add_provider`; not part of the public API.
     */
    public function filterSitemapProvider(mixed $provider, mixed $name): mixed
    {
        if ($name === self::USERS_SITEMAP_PROVIDER) {
            return false;
        }

        return $provider;
    }

    /**
     * @internal Hooked on `oembed_response_data`; not part of the public API.
     */
    public function filterOembedData(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        return $this->oembedAuthor->remove($data);
    }

    /**
     * @internal Hooked on `comment_class`; not part of the public API.
     */
    public function filterCommentClasses(mixed $classes, mixed $cssClass, mixed $commentId, mixed $comment): mixed
    {
        if (! is_array($classes)) {
            return $classes;
        }

        $authorClass = $this->authorClassOf($comment);

        if ($authorClass === null) {
            return $classes;
        }

        return $this->commentAuthorClass->remove($classes, $authorClass);
    }

    private function canListAccounts(): bool
    {
        $capability = $this->hooks->applyFilters('portcullis/account_listing_capability', self::LISTING_CAPABILITY);

        if (! is_string($capability)) {
            return false;
        }

        return current_user_can($capability);
    }

    /**
     * Built the way `get_comment_class()` does, so that only that class goes.
     */
    private function authorClassOf(mixed $comment): ?string
    {
        if (! $comment instanceof WP_Comment) {
            return null;
        }

        $author = get_userdata((int) $comment->user_id);

        if ($author === false) {
            return null;
        }

        return self::COMMENT_AUTHOR_CLASS_PREFIX.sanitize_html_class($author->user_nicename, (string) $comment->user_id);
    }
}
