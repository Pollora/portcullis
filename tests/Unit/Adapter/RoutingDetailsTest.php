<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\In\WordPress\HiddenLoginRouter;
use Pollora\Portcullis\Adapter\In\WordPress\StockAliasRouter;
use Pollora\Portcullis\Adapter\Out\WordPress\ThemeNotFoundResponder;
use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Application\Service\ClassifyStockAlias;
use Pollora\Portcullis\Application\Service\GuardDefaultEndpoints;
use Pollora\Portcullis\Application\Service\MatchHiddenLoginRequest;
use Pollora\Portcullis\Domain\Model\DefaultEndpoint;
use Pollora\Portcullis\Domain\Model\LoginSlug;
use Pollora\Portcullis\Tests\Support\FakeLoginScreen;
use Pollora\Portcullis\Tests\Support\FakeNotFound;
use Pollora\Portcullis\Tests\Support\FakeRequestContext;
use Pollora\Portcullis\Tests\Support\RecordingHooks;
use Pollora\Portcullis\Tests\Support\ResponseSent;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

// The request would end right after the redirect: the stand-in ends it with an exception instead.
if (! function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($location, $status = 302, $x_redirect_by = 'WordPress'): never
    {
        throw new ResponseSent('redirect:'.$location);
    }
}

describe('StockAliasRouter', function (): void {
    it('redirects each of the five admin aliases core knows', function (string $path): void {
        Wp::$is404 = true;
        $GLOBALS['wp_rewrite'] = new WP_Rewrite(true);
        $router = new StockAliasRouter(new ClassifyStockAlias, new FakeRequestContext(uri: $path), new WordPressHookRegistrar);

        expect(fn () => $router->route())->toThrow(ResponseSent::class, 'redirect:https://example.test/wp/wp-admin');
    })->with(['/wp-admin', '/dashboard', '/admin', '/wp/dashboard', '/wp/admin']);

    it('leaves a 404 alone unless core would have redirected it', function (?WP_Rewrite $rewrite, bool $is404): void {
        Wp::$is404 = $is404;

        if ($rewrite !== null) {
            $GLOBALS['wp_rewrite'] = $rewrite;
        }

        (new StockAliasRouter(new ClassifyStockAlias, new FakeRequestContext(uri: '/admin'), new WordPressHookRegistrar))->route();

        expect(true)->toBeTrue();
    })->with([
        'no rewrite object' => [null, true],
        'plain permalinks' => [new WP_Rewrite(false), true],
        'not a 404' => [new WP_Rewrite(true), false],
    ]);

    it('leaves a login alias as the 404 it already is', function (string $path): void {
        Wp::$is404 = true;
        $GLOBALS['wp_rewrite'] = new WP_Rewrite(true);

        (new StockAliasRouter(new ClassifyStockAlias, new FakeRequestContext(uri: $path), new WordPressHookRegistrar))->route();

        expect(Wp::$statuses)->toBe([]);
    })->with(['/wp-login.php', '/login.php', '/login', '/wp/login']);

    it('takes core\'s handler off before hooking its own, at the same priority', function (): void {
        $hooks = new RecordingHooks;

        (new StockAliasRouter(new ClassifyStockAlias, new FakeRequestContext, $hooks))->register();

        expect($hooks->removed)->toBe([['template_redirect', 'wp_redirect_admin_locations', 1000]]);
    });
});

describe('ThemeNotFoundResponder', function (): void {
    beforeEach(function (): void {
        $this->saved = [$_GET, $_POST, $_REQUEST, $_SERVER];
    });

    afterEach(function (): void {
        [$_GET, $_POST, $_REQUEST, $_SERVER] = $this->saved;
    });

    it('asks for every removal at the priority core registered it with', function (): void {
        $hooks = new RecordingHooks;

        (new ThemeNotFoundResponder(new FakeRequestContext(uri: '/wp-admin/'), $hooks))->prepare();

        expect($hooks->removed)->toBe([
            ['template_redirect', '_wp_admin_bar_init', 0],
            ['admin_init', '_wp_admin_bar_init', 10],
            ['wp_body_open', 'wp_admin_bar_render', 0],
            ['wp_footer', 'wp_admin_bar_render', 1000],
            ['in_admin_header', 'wp_admin_bar_render', 0],
            ['template_redirect', 'redirect_canonical', 10],
        ]);
    });

    it('answers the root path for an empty request URI', function (): void {
        (new ThemeNotFoundResponder(new FakeRequestContext(uri: ''), new RecordingHooks))->prepare();

        expect($_SERVER['REQUEST_URI'])->toBe('/');
    });
});

describe('HiddenLoginRouter', function (): void {
    it('serves the login screen alone, even where the script is a default endpoint', function (): void {
        $notFound = new FakeNotFound;
        $context = new FakeRequestContext(uri: '/acces-secret', endpoint: DefaultEndpoint::Login);
        (new HiddenLoginRouter(
            LoginSlug::fromString('acces-secret'),
            $context,
            new MatchHiddenLoginRequest,
            new GuardDefaultEndpoints,
            new FakeLoginScreen,
            $notFound,
            new WordPressHookRegistrar,
        ))->route();

        expect($notFound->prepared)->toBeFalse()
            ->and(Wp::callbacks('wp_loaded'))->toHaveCount(1);
    });
});
