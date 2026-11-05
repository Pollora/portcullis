<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\In\WordPress\AccountEnumerationGuard;
use Pollora\Portcullis\Adapter\In\WordPress\AuthorArchiveGuard;
use Pollora\Portcullis\Adapter\In\WordPress\AuthorLinkRewriter;
use Pollora\Portcullis\Application\Service\ConcealAuthorArchive;
use Pollora\Portcullis\Application\Service\RemoveAccountRoutes;
use Pollora\Portcullis\Application\Service\RemoveCommentAuthorClass;
use Pollora\Portcullis\Application\Service\RemoveOembedAuthor;
use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;
use Pollora\Portcullis\Tests\Support\RemovableHookRegistrar;

/*
 * The account enumeration adapters on a real WordPress installation. Every test
 * registers its own adapters and removes them afterwards.
 */

require_once __DIR__.'/bootstrap.php';

if (! defined('ABSPATH') || ! isset($GLOBALS['wpdb'])) {
    it('needs a WordPress installation', function (): void {})
        ->skip('Set PORTCULLIS_WP_LOAD to the wp-load.php of a WordPress installation.');

    return;
}

function registerAccountEnumerationGuard(HookRegistrarPort $hooks): void
{
    (new AccountEnumerationGuard(
        new RemoveAccountRoutes,
        new RemoveCommentAuthorClass,
        new RemoveOembedAuthor,
        $hooks,
    ))->register();
}

function registerAuthorArchiveGuard(AuthorArchivePolicy $policy, HookRegistrarPort $hooks): void
{
    (new AuthorArchiveGuard(new ConcealAuthorArchive($policy), $hooks))->register();
}

function enumerationUser(string $role): int
{
    $login = 'portcullis-'.$role.'-'.wp_generate_password(8, false);

    return (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role]);
}

function enumerationPost(int $authorId): int
{
    return (int) wp_insert_post(['post_author' => $authorId, 'post_title' => 'Hello', 'post_status' => 'publish']);
}

/**
 * Runs the front controller on a query, the way a page request would.
 *
 * @param  array<string, string>  $queryVars
 */
function resolveFrontEnd(array $queryVars): WP_Query
{
    $GLOBALS['wp']->main($queryVars);

    return $GLOBALS['wp_query'];
}

function usePermalinks(string $structure): void
{
    update_option('permalink_structure', $structure);
    $GLOBALS['wp_rewrite']->init();
}

beforeEach(function (): void {
    $this->hooks = new RemovableHookRegistrar;
    $this->permalinkStructure = get_option('permalink_structure');
    wp_set_current_user(0);
});

afterEach(function (): void {
    $this->hooks->removeAll();
    $GLOBALS['current_screen'] = null;
    usePermalinks((string) $this->permalinkStructure);
    wp_set_current_user(0);
});

it('removes the account routes for anonymous visitors only', function (): void {
    registerAccountEnumerationGuard($this->hooks);

    expect(rest_get_server()->get_routes())->not()->toHaveKey('/wp/v2/users');

    wp_set_current_user(enumerationUser('editor'));

    expect(rest_get_server()->get_routes())->toHaveKey('/wp/v2/users');
});

it('keeps the account routes for a filtered capability', function (): void {
    registerAccountEnumerationGuard($this->hooks);
    $capability = static fn (): string => 'read';
    add_filter('portcullis/account_listing_capability', $capability);
    wp_set_current_user(enumerationUser('subscriber'));

    try {
        expect(rest_get_server()->get_routes())->toHaveKey('/wp/v2/users');
    } finally {
        remove_filter('portcullis/account_listing_capability', $capability);
    }
});

it('removes the account routes when the capability filter returns nonsense', function (): void {
    registerAccountEnumerationGuard($this->hooks);
    $capability = static fn (): array => [];
    add_filter('portcullis/account_listing_capability', $capability);
    wp_set_current_user(enumerationUser('editor'));

    try {
        expect(rest_get_server()->get_routes())->not()->toHaveKey('/wp/v2/users');
    } finally {
        remove_filter('portcullis/account_listing_capability', $capability);
    }
});

it('leaves REST requests filtered by author alone', function (): void {
    registerAccountEnumerationGuard($this->hooks);
    registerAuthorArchiveGuard(AuthorArchivePolicy::Closed, $this->hooks);
    $author = enumerationUser('author');
    $post = enumerationPost($author);

    $queryVars = ['rest_route' => '/wp/v2/posts', 'author' => (string) $author];

    expect(apply_filters('request', $queryVars))->toBe($queryVars);

    $request = new WP_REST_Request('GET', '/wp/v2/posts');
    $request->set_query_params(['author' => $author]);
    $response = rest_do_request($request);

    expect($response->get_status())->toBe(200)
        ->and(array_column($response->get_data(), 'id'))->toBe([$post]);
});

it('drops the users sitemap only', function (): void {
    registerAccountEnumerationGuard($this->hooks);

    expect(apply_filters('wp_sitemaps_add_provider', 'provider', 'users'))->toBeFalse()
        ->and(apply_filters('wp_sitemaps_add_provider', 'provider', 'posts'))->toBe('provider');
});

it('removes the author from oEmbed responses', function (): void {
    registerAccountEnumerationGuard($this->hooks);
    $post = enumerationPost(enumerationUser('author'));

    expect(get_oembed_response_data($post, 600))
        ->not()->toHaveKey('author_name')
        ->not()->toHaveKey('author_url')
        ->toHaveKey('provider_name');
});

it('removes only the class built from the comment author slug', function (): void {
    registerAccountEnumerationGuard($this->hooks);
    $author = enumerationUser('subscriber');
    $comment = wp_insert_comment(['comment_post_ID' => enumerationPost($author), 'user_id' => $author, 'comment_content' => 'Hi']);
    $slugClass = 'comment-author-'.sanitize_html_class(get_userdata($author)->user_nicename, (string) $author);

    $classes = get_comment_class('comment-author-highlight', $comment);

    expect($classes)->not()->toContain($slugClass)
        ->and($classes)->toContain('comment-author-highlight', 'byuser');
});

it('answers the identifier shortcut with a 404 under pretty permalinks', function (): void {
    usePermalinks('/%postname%/');
    registerAuthorArchiveGuard(AuthorArchivePolicy::PublicWithPosts, $this->hooks);
    $author = enumerationUser('author');
    enumerationPost($author);

    expect(resolveFrontEnd(['author' => (string) $author])->is_404())->toBeTrue();
});

it('keeps the identifier archive without pretty permalinks, where it is the canonical URL', function (): void {
    usePermalinks('');
    registerAuthorArchiveGuard(AuthorArchivePolicy::PublicWithPosts, $this->hooks);
    $author = enumerationUser('author');
    enumerationPost($author);

    expect(resolveFrontEnd(['author' => (string) $author])->is_author())->toBeTrue();
});

it('keeps a public archive that lists posts', function (): void {
    usePermalinks('/%postname%/');
    registerAuthorArchiveGuard(AuthorArchivePolicy::PublicWithPosts, $this->hooks);
    $author = enumerationUser('author');
    enumerationPost($author);

    expect(resolveFrontEnd(['author_name' => get_userdata($author)->user_nicename])->is_author())->toBeTrue();
});

it('answers a 404 for the archive of an account without posts', function (): void {
    usePermalinks('/%postname%/');
    registerAuthorArchiveGuard(AuthorArchivePolicy::PublicWithPosts, $this->hooks);
    $customer = enumerationUser('subscriber');

    expect(resolveFrontEnd(['author_name' => get_userdata($customer)->user_nicename])->is_404())->toBeTrue();
});

it('answers a 404 for every archive once they are closed', function (): void {
    usePermalinks('/%postname%/');
    registerAuthorArchiveGuard(AuthorArchivePolicy::Closed, $this->hooks);
    $author = enumerationUser('author');
    enumerationPost($author);

    expect(resolveFrontEnd(['author_name' => get_userdata($author)->user_nicename])->is_404())->toBeTrue();
});

it('leaves the administration post lists alone', function (): void {
    usePermalinks('/%postname%/');
    registerAuthorArchiveGuard(AuthorArchivePolicy::Closed, $this->hooks);
    $author = enumerationUser('author');
    enumerationPost($author);
    require_once ABSPATH.'wp-admin/includes/class-wp-screen.php';
    require_once ABSPATH.'wp-admin/includes/screen.php';
    set_current_screen('edit');

    expect(resolveFrontEnd(['author' => (string) $author])->is_404())->toBeFalse();
});

it('leaves a query that looks no author up alone', function (): void {
    registerAuthorArchiveGuard(AuthorArchivePolicy::Closed, $this->hooks);
    $post = enumerationPost(enumerationUser('author'));

    expect(resolveFrontEnd(['p' => (string) $post])->is_single())->toBeTrue();
});

it('points author links home, or where the filter says', function (): void {
    (new AuthorLinkRewriter($this->hooks))->register();
    $author = enumerationUser('author');

    expect(get_author_posts_url($author))->toBe(home_url('/'));

    $team = static fn (string $home, int $authorId): string => home_url('/team/#'.$authorId);
    add_filter('portcullis/author_link', $team, 10, 2);

    try {
        expect(get_author_posts_url($author))->toBe(home_url('/team/#'.$author));
    } finally {
        remove_filter('portcullis/author_link', $team, 10);
    }
});
