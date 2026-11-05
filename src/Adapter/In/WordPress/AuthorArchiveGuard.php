<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\In\WordPress;

use Pollora\Portcullis\Application\Service\ConcealAuthorArchive;
use Pollora\Portcullis\Domain\Model\AuthorArchiveUrl;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;
use WP_Query;
use WP_Rewrite;

/**
 * Decides on the resolved main query, where core decides its own 404s: REST
 * requests never get there, and query variables keep their meaning.
 */
final class AuthorArchiveGuard
{
    public function __construct(
        private readonly ConcealAuthorArchive $archive,
        private readonly HookRegistrarPort $hooks,
    ) {}

    public function register(): void
    {
        $this->hooks->addFilter('pre_handle_404', [$this, 'filterNotFound'], PHP_INT_MAX, 2);
    }

    /**
     * @internal Hooked on `pre_handle_404`; not part of the public API.
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

    private function usesPrettyPermalinks(): bool
    {
        $rewrite = $GLOBALS['wp_rewrite'] ?? null;

        if (! $rewrite instanceof WP_Rewrite) {
            return false;
        }

        return $rewrite->using_permalinks();
    }
}
