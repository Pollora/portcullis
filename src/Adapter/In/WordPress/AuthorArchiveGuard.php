<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\In\WordPress;

use Pollora\Portcullis\Application\Service\ConcealAuthorArchive;
use Pollora\Portcullis\Domain\Model\AuthorArchiveUrl;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;
use WP_Query;
use WP_Rewrite;

/**
 * Answers the theme's 404 for the author archives that the archive policy
 * conceals, and for `?author=<id>` under pretty permalinks.
 *
 * Decides on the resolved main query, where core decides its own 404s: REST
 * requests never get there, and query variables keep their meaning.
 */
final class AuthorArchiveGuard
{
    /**
     * @param  ConcealAuthorArchive  $archive  Decides whether an archive is concealed.
     * @param  HookRegistrarPort  $hooks  Hook system of the host.
     */
    public function __construct(
        private readonly ConcealAuthorArchive $archive,
        private readonly HookRegistrarPort $hooks,
    ) {}

    /**
     * Hooks `pre_handle_404`, at `PHP_INT_MAX`.
     */
    public function register(): void
    {
        $this->hooks->addFilter('pre_handle_404', [$this, 'filterNotFound'], PHP_INT_MAX, 2);
    }

    /**
     * Turns a front-end author archive that the policy conceals into a 404: the
     * query is flagged as not found, the status is sent and caching is turned off.
     * Answering `true` tells core the 404 is already handled.
     *
     * @internal Hooked on `pre_handle_404`; not part of the public API.
     *
     * @param  mixed  $handled  Whether a 404 was already handled; returned untouched unless `false`.
     * @param  mixed  $query  The main query; anything but a `WP_Query` is returned untouched.
     */
    public function filterNotFound(mixed $handled, mixed $query): mixed
    {
        if ($handled !== false) {
            return $handled;
        }

        if (! $this->isFrontEndAuthorArchive($query)) {
            return $handled;
        }

        if (! $this->archive->conceals($this->archiveUrl($query), $query->post_count)) {
            return $handled;
        }

        $query->set_404();
        status_header(404);
        nocache_headers();

        return true;
    }

    /**
     * The administration resolves its post lists through the same query.
     */
    private function isFrontEndAuthorArchive(mixed $query): bool
    {
        if (! $query instanceof WP_Query) {
            return false;
        }

        if (is_admin()) {
            return false;
        }

        return $query->is_author();
    }

    /**
     * Tells which kind of URL the archive was requested through. `?author=<id>`
     * is the shortcut under pretty permalinks, where core redirects it to the
     * slug; a request that names the author (`/author/<slug>`) or any request
     * without pretty permalinks is the canonical URL.
     */
    private function archiveUrl(WP_Query $query): AuthorArchiveUrl
    {
        if ($query->get('author_name') !== '') {
            return AuthorArchiveUrl::Canonical;
        }

        if ($this->usesPrettyPermalinks()) {
            return AuthorArchiveUrl::Shortcut;
        }

        return AuthorArchiveUrl::Canonical;
    }

    /**
     * Whether the site uses pretty permalinks; `false` when the rewrite object is
     * not there.
     */
    private function usesPrettyPermalinks(): bool
    {
        $rewrite = $GLOBALS['wp_rewrite'] ?? null;

        if (! $rewrite instanceof WP_Rewrite) {
            return false;
        }

        return $rewrite->using_permalinks();
    }
}
