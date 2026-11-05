<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\In\WordPress;

use Pollora\Portcullis\Application\Service\RemoveAccountRoutes;
use Pollora\Portcullis\Application\Service\RemoveCommentAuthorClass;
use Pollora\Portcullis\Application\Service\RemoveOembedAuthor;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;
use WP_Comment;

/**
 * Stops WordPress from publishing account slugs through four surfaces that are
 * not the author archives: the REST users routes, the users sitemap, the author
 * fields of oEmbed responses and the `comment-author-<slug>` comment class.
 *
 * The slug is derived from the login, so each of these gives away half of the
 * credentials the hidden login and the brute-force protection assume is unknown.
 * The four filters run at `PHP_INT_MAX`, after any other callback.
 */
final class AccountEnumerationGuard
{
    /**
     * Needed by the block editor's author selector.
     */
    private const LISTING_CAPABILITY = 'edit_posts';

    /**
     * Name of core's users sitemap provider.
     */
    private const USERS_SITEMAP_PROVIDER = 'users';

    /**
     * Prefix of the class `get_comment_class()` adds for a registered user: what
     * follows it is the author's slug.
     */
    private const COMMENT_AUTHOR_CLASS_PREFIX = 'comment-author-';

    /**
     * @param  RemoveAccountRoutes  $accountRoutes  Drops the REST users routes.
     * @param  RemoveCommentAuthorClass  $commentAuthorClass  Drops the author class of a comment.
     * @param  RemoveOembedAuthor  $oembedAuthor  Drops the author fields of an oEmbed response.
     * @param  HookRegistrarPort  $hooks  Hook system of the host.
     */
    public function __construct(
        private readonly RemoveAccountRoutes $accountRoutes,
        private readonly RemoveCommentAuthorClass $commentAuthorClass,
        private readonly RemoveOembedAuthor $oembedAuthor,
        private readonly HookRegistrarPort $hooks,
    ) {}

    /**
     * Hooks the four filters: `rest_endpoints`, `wp_sitemaps_add_provider`,
     * `oembed_response_data` and `comment_class`.
     */
    public function register(): void
    {
        $this->hooks->addFilter('rest_endpoints', [$this, 'filterRoutes'], PHP_INT_MAX, 1);
        $this->hooks->addFilter('wp_sitemaps_add_provider', [$this, 'filterSitemapProvider'], PHP_INT_MAX, 2);
        $this->hooks->addFilter('oembed_response_data', [$this, 'filterOembedData'], PHP_INT_MAX, 1);
        $this->hooks->addFilter('comment_class', [$this, 'filterCommentClasses'], PHP_INT_MAX, 4);
    }

    /**
     * Removes `/wp/v2/users` and `/wp/v2/users/{id}` from the REST routes, unless
     * the current user can list accounts: `edit_posts` by default, which the block
     * editor's author selector needs, widened or narrowed through the
     * `portcullis/account_listing_capability` filter. `/wp/v2/users/me` stays: it
     * only ever describes the requester.
     *
     * @internal Hooked on `rest_endpoints`; not part of the public API.
     *
     * @param  mixed  $routes  Registered REST routes; anything but an array is returned untouched.
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
     * Drops core's users sitemap, which lists the author archives, and keeps every
     * other provider.
     *
     * @internal Hooked on `wp_sitemaps_add_provider`; not part of the public API.
     *
     * @param  mixed  $provider  The sitemap provider about to be registered.
     * @param  mixed  $name  Name it is registered under.
     */
    public function filterSitemapProvider(mixed $provider, mixed $name): mixed
    {
        if ($name === self::USERS_SITEMAP_PROVIDER) {
            return false;
        }

        return $provider;
    }

    /**
     * Removes `author_name` and `author_url` from an oEmbed response: the second
     * one is the author archive, and both fields are optional in oEmbed.
     *
     * @internal Hooked on `oembed_response_data`; not part of the public API.
     *
     * @param  mixed  $data  The response data; anything but an array is returned untouched.
     */
    public function filterOembedData(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        return $this->oembedAuthor->remove($data);
    }

    /**
     * Removes the `comment-author-<slug>` class of a comment left by a registered
     * user. `byuser` and `bypostauthor` stay: they carry no slug.
     *
     * @internal Hooked on `comment_class`; not part of the public API.
     *
     * @param  mixed  $classes  The comment's CSS classes; anything but an array is returned untouched.
     * @param  mixed  $cssClass  Additional classes the caller asked for, unused.
     * @param  mixed  $commentId  Identifier of the comment, unused.
     * @param  mixed  $comment  The comment: no class is removed unless it is a `WP_Comment` by a known user.
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

    /**
     * Whether the current user may still list accounts. The capability goes
     * through the `portcullis/account_listing_capability` filter, and an answer
     * that is not a string denies everyone.
     */
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
