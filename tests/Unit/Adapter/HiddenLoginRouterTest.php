<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\In\WordPress\HiddenLoginRouter;
use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Application\Service\GuardDefaultEndpoints;
use Pollora\Portcullis\Application\Service\MatchHiddenLoginRequest;
use Pollora\Portcullis\Domain\Model\DefaultEndpoint;
use Pollora\Portcullis\Domain\Model\LoginSlug;
use Pollora\Portcullis\Tests\Support\FakeLoginScreen;
use Pollora\Portcullis\Tests\Support\FakeNotFound;
use Pollora\Portcullis\Tests\Support\FakeRequestContext;
use Pollora\Portcullis\Tests\Support\ResponseSent;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

function hiddenRouter(FakeRequestContext $context, FakeLoginScreen $screen, FakeNotFound $notFound): HiddenLoginRouter
{
    $router = new HiddenLoginRouter(
        LoginSlug::fromString('acces-secret'),
        $context,
        new MatchHiddenLoginRequest,
        new GuardDefaultEndpoints,
        $screen,
        $notFound,
        new WordPressHookRegistrar,
    );
    $router->register();

    return $router;
}

beforeEach(function (): void {
    $this->screen = new FakeLoginScreen;
    $this->notFound = new FakeNotFound;
});

it('routes on plugins_loaded, before anything else', function (): void {
    hiddenRouter(new FakeRequestContext, $this->screen, $this->notFound);

    expect(Wp::$hooks['plugins_loaded'])->toHaveKey(1)
        ->and(Wp::callbacks('plugins_loaded'))->toHaveCount(1);
});

it('serves the login screen on the secret slug, once WordPress has loaded', function (): void {
    $context = new FakeRequestContext(uri: '/acces-secret/?action=lostpassword');
    hiddenRouter($context, $this->screen, $this->notFound);

    do_action('plugins_loaded');

    expect($this->screen->prepared?->value())->toBe('acces-secret')
        ->and($this->notFound->prepared)->toBeFalse()
        ->and(fn () => do_action('wp_loaded'))->toThrow(ResponseSent::class, 'login screen');
});

it('serves the login screen on the secret slug of a sub-directory install', function (): void {
    $context = new FakeRequestContext(uri: '/blog/acces-secret', home: 'blog');
    hiddenRouter($context, $this->screen, $this->notFound);

    do_action('plugins_loaded');

    expect($this->screen->prepared)->not->toBeNull();
});

it('answers wp-login.php with a 404, logged in or not', function (bool $loggedIn): void {
    $context = new FakeRequestContext(uri: '/wp-login.php', endpoint: DefaultEndpoint::Login, loggedIn: $loggedIn);
    hiddenRouter($context, $this->screen, $this->notFound);

    do_action('plugins_loaded');

    expect($this->notFound->prepared)->toBeTrue()
        ->and($this->screen->prepared)->toBeNull()
        ->and(fn () => do_action('wp_loaded'))->toThrow(ResponseSent::class, '404');
})->with([[false], [true]]);

it('answers wp-admin with a 404 for anonymous visitors only', function (): void {
    $context = new FakeRequestContext(uri: '/wp-admin/', endpoint: DefaultEndpoint::Admin);
    hiddenRouter($context, $this->screen, $this->notFound);

    do_action('plugins_loaded');

    expect($this->notFound->prepared)->toBeTrue();
});

it('leaves wp-admin alone for an authenticated user', function (): void {
    $context = new FakeRequestContext(uri: '/wp-admin/', endpoint: DefaultEndpoint::Admin, loggedIn: true);
    hiddenRouter($context, $this->screen, $this->notFound);

    do_action('plugins_loaded');
    do_action('wp_loaded');

    expect($this->notFound->prepared)->toBeFalse();
});

it('does not touch a request that targets neither endpoint', function (): void {
    $context = new FakeRequestContext(uri: '/contact/');
    hiddenRouter($context, $this->screen, $this->notFound);

    do_action('plugins_loaded');
    do_action('wp_loaded');

    expect($this->notFound->prepared)->toBeFalse()
        ->and($this->screen->prepared)->toBeNull();
});

it('does not touch WP-CLI or cron, even on a login script', function (): void {
    $context = new FakeRequestContext(uri: '/wp-login.php', endpoint: DefaultEndpoint::Login, nonHttp: true);
    hiddenRouter($context, $this->screen, $this->notFound);

    do_action('plugins_loaded');
    do_action('wp_loaded');

    expect($this->notFound->prepared)->toBeFalse();
});

it('tolerates a login action the host explicitly allows', function (): void {
    $context = new FakeRequestContext(uri: '/wp-login.php', endpoint: DefaultEndpoint::Login, action: 'postpass');
    add_filter('portcullis/allowed_default_actions', static fn (array $actions): array => [...$actions, 'postpass']);
    hiddenRouter($context, $this->screen, $this->notFound);

    do_action('plugins_loaded');

    expect($this->notFound->prepared)->toBeFalse();
});

it('still honours the 1.x filter that allows an action', function (): void {
    $context = new FakeRequestContext(uri: '/wp-login.php', endpoint: DefaultEndpoint::Login, action: 'postpass');
    add_filter('hidden_login/allowed_default_actions', static fn (): array => ['postpass']);
    hiddenRouter($context, $this->screen, $this->notFound);

    do_action('plugins_loaded');

    expect($this->notFound->prepared)->toBeFalse();
});

it('blocks everything when the allow filter returns garbage', function (): void {
    $context = new FakeRequestContext(uri: '/wp-login.php', endpoint: DefaultEndpoint::Login, action: 'postpass');
    add_filter('portcullis/allowed_default_actions', static fn (): string => 'postpass');
    hiddenRouter($context, $this->screen, $this->notFound);

    do_action('plugins_loaded');

    expect($this->notFound->prepared)->toBeTrue();
});

it('ignores non-string entries in the allowed actions', function (): void {
    $context = new FakeRequestContext(uri: '/wp-login.php', endpoint: DefaultEndpoint::Login, action: '1');
    add_filter('portcullis/allowed_default_actions', static fn (): array => [1, true, null]);
    hiddenRouter($context, $this->screen, $this->notFound);

    do_action('plugins_loaded');

    expect($this->notFound->prepared)->toBeTrue();
});
