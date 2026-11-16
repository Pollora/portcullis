<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\ThemeNotFoundResponder;
use Pollora\Portcullis\Adapter\Out\WordPress\WpLoginScreenRenderer;
use Pollora\Portcullis\Domain\Model\LoginSlug;
use Pollora\Portcullis\Tests\Support\FakeRequestContext;
use Pollora\Portcullis\Tests\Support\RecordingHooks;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

beforeEach(function (): void {
    $this->saved = [$_GET, $_POST, $_REQUEST, $_SERVER];
});

afterEach(function (): void {
    [$_GET, $_POST, $_REQUEST, $_SERVER] = $this->saved;
});

describe('ThemeNotFoundResponder::prepare', function (): void {
    it('turns the blocked request into a clean GET of the same path, whatever was sent with it', function (): void {
        $_GET = ['s' => 'x'];
        $_POST = ['log' => 'admin', 'pwd' => 'secret'];
        $_REQUEST = ['log' => 'admin', 'pwd' => 'secret', 's' => 'x'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $responder = new ThemeNotFoundResponder(new FakeRequestContext(uri: '/wp-login.php?redirect_to=https%3A%2F%2Fevil.test'), new RecordingHooks);

        $responder->prepare();

        // Credentials posted to a hidden endpoint are not left lying around for
        // the theme's template, nor for any plugin that reads $_REQUEST.
        expect($_GET)->toBe([])
            ->and($_POST)->toBe([])
            ->and($_REQUEST)->toBe([])
            ->and($_SERVER['REQUEST_URI'])->toBe('/wp-login.php')
            ->and($_SERVER['REQUEST_METHOD'])->toBe('GET')
            ->and($GLOBALS['pagenow'])->toBe('index.php');
    });

    it('falls back to the root path when the URI has none', function (): void {
        $responder = new ThemeNotFoundResponder(new FakeRequestContext(uri: '?only=query'), new RecordingHooks);

        $responder->prepare();

        expect($_SERVER['REQUEST_URI'])->toBe('/');
    });

    it('keeps WordPress from redirecting the refusal onto a similarly named post', function (): void {
        $hooks = new RecordingHooks;

        (new ThemeNotFoundResponder(new FakeRequestContext(uri: '/wp-admin/'), $hooks))->prepare();

        expect($hooks->removedCallback('template_redirect', 'redirect_canonical'))->toBeTrue();
    });

    it('takes the admin bar off every place it can come from', function (): void {
        $hooks = new RecordingHooks;

        (new ThemeNotFoundResponder(new FakeRequestContext(uri: '/wp-admin/'), $hooks))->prepare();

        expect($hooks->removedCallback('template_redirect', '_wp_admin_bar_init'))->toBeTrue()
            ->and($hooks->removedCallback('admin_init', '_wp_admin_bar_init'))->toBeTrue()
            ->and($hooks->removedCallback('wp_body_open', 'wp_admin_bar_render'))->toBeTrue()
            ->and($hooks->removedCallback('wp_footer', 'wp_admin_bar_render'))->toBeTrue()
            ->and($hooks->removedCallback('in_admin_header', 'wp_admin_bar_render'))->toBeTrue()
            ->and(apply_filters('show_admin_bar', true))->toBeFalse();
    });
});

describe('WpLoginScreenRenderer::prepare', function (): void {
    function prepareLogin(string $uri, string $home = ''): void
    {
        (new WpLoginScreenRenderer(new FakeRequestContext(uri: $uri, home: $home)))->prepare(LoginSlug::fromString('acces-secret'));
    }

    it('makes WordPress believe it is serving wp-login.php', function (): void {
        prepareLogin('/acces-secret');

        expect($GLOBALS['pagenow'])->toBe('wp-login.php');
    });

    it('presents the canonical slug path, so that every form action resolves back to it', function (): void {
        prepareLogin('/acces-secret/');

        expect($_SERVER['REQUEST_URI'])->toBe('/acces-secret');
    });

    it('keeps the query string, which carries the action and the redirect target', function (): void {
        prepareLogin('/acces-secret?action=lostpassword&redirect_to=%2Fwp-admin%2F');

        expect($_SERVER['REQUEST_URI'])->toBe('/acces-secret?action=lostpassword&redirect_to=%2Fwp-admin%2F');
    });

    it('keeps the home path of a sub-directory install', function (): void {
        prepareLogin('/blog/acces-secret/?x=1', 'blog');

        expect($_SERVER['REQUEST_URI'])->toBe('/blog/acces-secret?x=1');
    });
});
