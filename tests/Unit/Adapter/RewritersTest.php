<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\In\WordPress\AuthorLinkRewriter;
use Pollora\Portcullis\Adapter\In\WordPress\LoginUrlRewriter;
use Pollora\Portcullis\Adapter\In\WordPress\StockAliasRouter;
use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Application\Service\ClassifyStockAlias;
use Pollora\Portcullis\Application\Service\RewriteLoginUrl;
use Pollora\Portcullis\Domain\Model\LoginSlug;
use Pollora\Portcullis\Tests\Support\FakeRequestContext;
use Pollora\Portcullis\Tests\Support\RecordingHooks;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

describe('LoginUrlRewriter', function (): void {
    beforeEach(function (): void {
        $this->rewriter = new LoginUrlRewriter(LoginSlug::fromString('acces-secret'), new RewriteLoginUrl, new WordPressHookRegistrar);
        $this->rewriter->register();
    });

    it('registers on the three places a login URL is built', function (): void {
        expect(Wp::callbacks('site_url'))->toHaveCount(1)
            ->and(Wp::callbacks('network_site_url'))->toHaveCount(1)
            ->and(Wp::callbacks('wp_redirect'))->toHaveCount(1);
    });

    it('never lets wp-login.php leak through site_url()', function (): void {
        $url = apply_filters('site_url', 'https://example.test/wp/wp-login.php?action=lostpassword', 'wp-login.php?action=lostpassword', 'login', null);

        expect($url)->toBe('https://example.test/acces-secret?action=lostpassword');
    });

    it('rewrites network_site_url() on a network, against the network home', function (): void {
        Wp::$multisite = true;

        $url = apply_filters('network_site_url', 'https://example.test/wp/wp-login.php', 'wp-login.php', 'login');

        expect($url)->toBe('https://example.test/acces-secret');
    });

    it('rewrites network_site_url() on a single site, against the home URL', function (): void {
        $url = apply_filters('network_site_url', 'https://example.test/wp/wp-login.php?x=1#frag', 'wp-login.php', null);

        expect($url)->toBe('https://example.test/acces-secret?x=1#frag');
    });

    it('rewrites a redirect to the login script', function (): void {
        expect(apply_filters('wp_redirect', 'wp-login.php?checkemail=confirm'))->toBe('https://example.test/acces-secret?checkemail=confirm');
    });

    it('leaves every other URL alone', function (string $hook): void {
        $url = 'https://example.test/wp/wp-admin/options.php';

        expect(apply_filters($hook, $url, 'x', null, null))->toBe($url);
    })->with(['site_url', 'network_site_url', 'wp_redirect']);

    it('does not recurse when resolving the base URL re-enters the filter', function (): void {
        // A plugin hooked on home_url that itself builds a login URL would send
        // the rewriter into a loop; the nested call must use the stored option.
        Wp::$options['home'] = 'https://stored.example.test/';
        $nested = null;
        Wp::$onHomeUrl = function () use (&$nested): void {
            Wp::$onHomeUrl = null;
            $nested = $this->rewriter->filterSiteUrl('https://example.test/wp/wp-login.php', 'wp-login.php');
        };

        $outer = $this->rewriter->filterSiteUrl('https://example.test/wp/wp-login.php', 'wp-login.php');

        expect($nested)->toBe('https://stored.example.test/acces-secret')
            ->and($outer)->toBe('https://example.test/acces-secret');
    });
});

describe('AuthorLinkRewriter', function (): void {
    beforeEach(function (): void {
        (new AuthorLinkRewriter(new WordPressHookRegistrar))->register();
    });

    it('points every author link at the home page, never at the account slug', function (): void {
        expect(apply_filters('author_link', 'https://example.test/author/admin/', 3))->toBe('https://example.test/');
    });

    it('lets the host choose another destination', function (): void {
        add_filter('portcullis/author_link', static fn (string $home, int $id): string => $home.'team/'.$id, 10, 2);

        expect(apply_filters('author_link', 'https://example.test/author/admin/', '3'))->toBe('https://example.test/team/3');
    });

    it('falls back to the home page when the host returns something that is not a link', function (): void {
        add_filter('portcullis/author_link', static fn (): array => ['x']);

        expect(apply_filters('author_link', 'https://example.test/author/admin/', 3))->toBe('https://example.test/');
    });
});

describe('StockAliasRouter', function (): void {
    function aliasRouter(string $uri, ?RecordingHooks $hooks = null): StockAliasRouter
    {
        return new StockAliasRouter(new ClassifyStockAlias, new FakeRequestContext(uri: $uri), $hooks ?? new WordPressHookRegistrar);
    }

    it('takes core\'s admin-alias redirect off and routes late in its place', function (): void {
        $hooks = new RecordingHooks;
        aliasRouter('/admin', $hooks)->register();

        expect($hooks->removedCallback('template_redirect', 'wp_redirect_admin_locations'))->toBeTrue()
            ->and($hooks->removed[0][2])->toBe(1000)
            ->and(Wp::$hooks['template_redirect'])->toHaveKey(1000);
    });

    it('does nothing when the request is not a 404', function (): void {
        $GLOBALS['wp_rewrite'] = new WP_Rewrite(true);
        Wp::$is404 = false;

        aliasRouter('/admin')->route();

        expect(Wp::$statuses)->toBe([]);
    });

    it('does nothing without pretty permalinks, where core would not have redirected either', function (): void {
        $GLOBALS['wp_rewrite'] = new WP_Rewrite(false);
        Wp::$is404 = true;

        aliasRouter('/admin')->route();

        expect(Wp::$statuses)->toBe([]);
    });

    it('does nothing before the rewrite rules exist', function (): void {
        Wp::$is404 = true;

        aliasRouter('/admin')->route();

        expect(Wp::$statuses)->toBe([]);
    });

    it('keeps the 404 of a login alias, which core would have redirected to the secret slug', function (string $uri): void {
        $GLOBALS['wp_rewrite'] = new WP_Rewrite(true);
        Wp::$is404 = true;

        aliasRouter($uri)->route();

        // Returning is the whole point: reaching the redirect would have exited
        // the process, and spelled the login URL out.
        expect(true)->toBeTrue();
    })->with(['/login', '/login.php', '/wp-login.php', '/wp/login']);

    it('keeps the 404 of a path that is no alias at all', function (): void {
        $GLOBALS['wp_rewrite'] = new WP_Rewrite(true);
        Wp::$is404 = true;

        aliasRouter('/some-missing-page')->route();

        expect(true)->toBeTrue();
    });
});
