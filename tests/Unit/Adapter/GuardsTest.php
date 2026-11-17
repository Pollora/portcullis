<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\In\WordPress\AccountEnumerationGuard;
use Pollora\Portcullis\Adapter\In\WordPress\AccountIdentifier;
use Pollora\Portcullis\Adapter\In\WordPress\AuthorArchiveGuard;
use Pollora\Portcullis\Adapter\In\WordPress\GenericLoginErrors;
use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Application\Service\ConcealAuthorArchive;
use Pollora\Portcullis\Application\Service\RemoveAccountRoutes;
use Pollora\Portcullis\Application\Service\RemoveCommentAuthorClass;
use Pollora\Portcullis\Application\Service\RemoveOembedAuthor;
use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

describe('AccountEnumerationGuard', function (): void {
    beforeEach(function (): void {
        (new AccountEnumerationGuard(
            new RemoveAccountRoutes,
            new RemoveCommentAuthorClass,
            new RemoveOembedAuthor,
            new WordPressHookRegistrar,
        ))->register();
    });

    it('hooks last on the four places an account slug is published', function (): void {
        foreach (['rest_endpoints', 'wp_sitemaps_add_provider', 'oembed_response_data', 'comment_class'] as $hook) {
            expect(Wp::$hooks[$hook])->toHaveKey(PHP_INT_MAX);
        }
    });

    it('removes the user routes from the REST index for visitors', function (): void {
        $routes = apply_filters('rest_endpoints', ['/wp/v2/users' => [], '/wp/v2/users/(?P<id>[\d]+)' => [], '/wp/v2/posts' => []]);

        expect(array_keys($routes))->toBe(['/wp/v2/posts']);
    });

    it('keeps the user routes for someone who can edit posts', function (): void {
        Wp::$capabilities = ['edit_posts'];
        $routes = ['/wp/v2/users' => [], '/wp/v2/posts' => []];

        expect(apply_filters('rest_endpoints', $routes))->toBe($routes);
    });

    it('lets the host pick the capability that may list accounts', function (): void {
        add_filter('portcullis/account_listing_capability', static fn (): string => 'list_users');
        $routes = ['/wp/v2/users' => []];

        expect(apply_filters('rest_endpoints', $routes))->toBe([]);

        Wp::$capabilities = ['list_users'];

        expect(apply_filters('rest_endpoints', $routes))->toBe($routes);
    });

    it('refuses to list accounts when the capability filter returns garbage', function (): void {
        add_filter('portcullis/account_listing_capability', static fn (): array => ['edit_posts']);
        Wp::$capabilities = ['edit_posts'];

        expect(apply_filters('rest_endpoints', ['/wp/v2/users' => []]))->toBe([]);
    });

    it('passes a non-array routes value through', function (): void {
        expect(apply_filters('rest_endpoints', 'weird'))->toBe('weird');
    });

    it('drops the users sitemap and keeps the others', function (): void {
        expect(apply_filters('wp_sitemaps_add_provider', 'provider', 'users'))->toBeFalse()
            ->and(apply_filters('wp_sitemaps_add_provider', 'provider', 'posts'))->toBe('provider');
    });

    it('removes the author from oEmbed data', function (): void {
        $data = apply_filters('oembed_response_data', ['title' => 'T', 'author_name' => 'admin', 'author_url' => 'https://example.test/author/admin/']);

        expect($data)->toBe(['title' => 'T']);
    });

    it('passes non-array oEmbed data through', function (): void {
        expect(apply_filters('oembed_response_data', false))->toBeFalse();
    });

    it('removes the account slug from a comment class', function (): void {
        Wp::$usersById[7] = new WP_User(7, 'admin', 'admin');
        $classes = ['comment', 'byuser', 'comment-author-admin', 'even'];

        $result = apply_filters('comment_class', $classes, '', 12, new WP_Comment(7));

        expect($result)->not->toContain('comment-author-admin')
            ->and($result)->toContain('comment', 'byuser', 'even');
    });

    it('leaves comment classes alone for a guest comment, an unknown author or a non-comment', function (): void {
        $classes = ['comment', 'comment-author-admin'];

        expect(apply_filters('comment_class', $classes, '', 1, new WP_Comment(0)))->toBe($classes)
            ->and(apply_filters('comment_class', $classes, '', 1, null))->toBe($classes)
            ->and(apply_filters('comment_class', 'not-an-array', '', 1, new WP_Comment(7)))->toBe('not-an-array');
    });
});

describe('AuthorArchiveGuard', function (): void {
    function archiveGuard(AuthorArchivePolicy $policy): void
    {
        (new AuthorArchiveGuard(new ConcealAuthorArchive($policy), new WordPressHookRegistrar))->register();
    }

    function handle404(mixed $query, mixed $handled = false): mixed
    {
        return apply_filters('pre_handle_404', $handled, $query);
    }

    it('answers the ?author=<id> shortcut with a 404 when pretty permalinks are on', function (): void {
        archiveGuard(AuthorArchivePolicy::PublicWithPosts);
        $GLOBALS['wp_rewrite'] = new WP_Rewrite(true);
        $query = new WP_Query(author: true, vars: ['author_name' => '']);
        $query->post_count = 5;

        expect(handle404($query))->toBeTrue()
            ->and($query->set404)->toBeTrue()
            ->and(Wp::$statuses)->toBe([404])
            ->and(Wp::$noCacheHeaders)->toBe(1);
    });

    it('lets a canonical archive that lists posts through', function (): void {
        archiveGuard(AuthorArchivePolicy::PublicWithPosts);
        $query = new WP_Query(author: true, vars: ['author_name' => 'jane']);
        $query->post_count = 3;

        expect(handle404($query))->toBeFalse()
            ->and($query->set404)->toBeFalse();
    });

    it('answers a canonical archive without posts with a 404, as it would reveal an account that never writes', function (): void {
        archiveGuard(AuthorArchivePolicy::PublicWithPosts);
        $query = new WP_Query(author: true, vars: ['author_name' => 'jane']);

        expect(handle404($query))->toBeTrue()
            ->and($query->set404)->toBeTrue();
    });

    it('closes every archive under the closed policy', function (): void {
        archiveGuard(AuthorArchivePolicy::Closed);
        $query = new WP_Query(author: true, vars: ['author_name' => 'jane']);
        $query->post_count = 10;

        expect(handle404($query))->toBeTrue();
    });

    it('treats ?author=<id> as the canonical form when permalinks are plain', function (): void {
        archiveGuard(AuthorArchivePolicy::PublicWithPosts);
        $GLOBALS['wp_rewrite'] = new WP_Rewrite(false);
        $query = new WP_Query(author: true);
        $query->post_count = 2;

        expect(handle404($query))->toBeFalse();
    });

    it('treats the shortcut as canonical while the rewrite object does not exist yet', function (): void {
        archiveGuard(AuthorArchivePolicy::PublicWithPosts);
        $query = new WP_Query(author: true);
        $query->post_count = 2;

        expect(handle404($query))->toBeFalse();
    });

    it('leaves a request another handler already settled alone', function (): void {
        archiveGuard(AuthorArchivePolicy::Closed);
        $query = new WP_Query(author: true, vars: ['author_name' => 'jane']);

        expect(handle404($query, true))->toBeTrue()
            ->and($query->set404)->toBeFalse();
    });

    it('does not touch queries that are not front-end author archives', function (): void {
        archiveGuard(AuthorArchivePolicy::Closed);

        expect(handle404(new WP_Query(author: false)))->toBeFalse()
            ->and(handle404('not-a-query'))->toBeFalse();

        Wp::$admin = true;
        $query = new WP_Query(author: true);

        expect(handle404($query))->toBeFalse()
            ->and($query->set404)->toBeFalse();
    });
});

describe('GenericLoginErrors', function (): void {
    beforeEach(function (): void {
        (new GenericLoginErrors(new WordPressHookRegistrar))->register();
    });

    it('answers every disclosing error with the same message', function (string $code): void {
        $errors = new WP_Error($code, 'The username "admin" is not registered on this site.');

        $filtered = apply_filters('wp_login_errors', $errors);

        expect($filtered->get_error_codes())->toBe([$code])
            ->and($filtered->get_error_message())->toBe('<strong>Error:</strong> Invalid username, email address or incorrect password.')
            ->and($filtered->get_error_message())->not->toContain('admin');
    })->with(['incorrect_password', 'invalid_email', 'invalid_username']);

    it('collapses several disclosing errors into one, keeping the first code', function (): void {
        $errors = new WP_Error('invalid_username', 'unknown user');
        $errors->add('incorrect_password', 'wrong password');

        $filtered = apply_filters('wp_login_errors', $errors);

        expect($filtered->get_error_codes())->toBe(['invalid_username']);
    });

    it('keeps the errors that disclose nothing', function (): void {
        $errors = new WP_Error('loggedout', 'You are now logged out.');
        $errors->add('incorrect_password', 'wrong password');

        $filtered = apply_filters('wp_login_errors', $errors);

        expect($filtered->get_error_codes())->toBe(['loggedout', 'incorrect_password'])
            ->and($filtered->get_error_message('loggedout'))->toBe('You are now logged out.');
    });

    it('does not touch an error object without a disclosing code', function (): void {
        $errors = new WP_Error('confirm', 'Check your email.');

        expect(apply_filters('wp_login_errors', $errors))->toBe($errors)
            ->and($errors->get_error_codes())->toBe(['confirm']);
    });

    it('passes something that is not an error object through', function (): void {
        expect(apply_filters('wp_login_errors', 'nothing'))->toBe('nothing');
    });
});

describe('AccountIdentifier', function (): void {
    it('identifies an account by its user ID whichever way the user signed in', function (): void {
        $user = new WP_User(42, 'jane', 'jane');
        Wp::$usersByLogin['jane'] = $user;
        Wp::$usersByEmail['jane@example.test'] = $user;
        $accounts = new AccountIdentifier;

        expect($accounts->forLogin('jane'))->toBe('user:42')
            ->and($accounts->forLogin(' jane '))->toBe('user:42')
            ->and($accounts->forLogin('jane@example.test'))->toBe('user:42')
            ->and($accounts->forUser($user))->toBe('user:42');
    });

    it('counts attempts at a name that does not exist under that name, so that probing cannot spread over variants', function (): void {
        $accounts = new AccountIdentifier;

        expect($accounts->forLogin('ghost'))->toBe('login:ghost')
            ->and($accounts->forLogin('ghost@example.test'))->toBe('login:ghost@example.test');
    });

    it('gives an empty login no identity', function (): void {
        expect((new AccountIdentifier)->forLogin('   '))->toBe('');
    });
});

describe('guards, the cases that look alike', function (): void {
    it('lets a canonical archive with posts through under pretty permalinks too', function (): void {
        (new AuthorArchiveGuard(new ConcealAuthorArchive(AuthorArchivePolicy::PublicWithPosts), new WordPressHookRegistrar))->register();
        $GLOBALS['wp_rewrite'] = new WP_Rewrite(true);
        $query = new WP_Query(author: true, vars: ['author_name' => 'jane']);
        $query->post_count = 5;

        expect(apply_filters('pre_handle_404', false, $query))->toBeFalse()
            ->and($query->set404)->toBeFalse();
    });

    it('hands back a comment class list that is not a list, even for a known author', function (): void {
        (new AccountEnumerationGuard(new RemoveAccountRoutes, new RemoveCommentAuthorClass, new RemoveOembedAuthor, new WordPressHookRegistrar))->register();
        Wp::$usersById[7] = new WP_User(7, 'jane', 'jane');

        expect(apply_filters('comment_class', 'not-an-array', '', 1, new WP_Comment(7)))->toBe('not-an-array');
    });
});
