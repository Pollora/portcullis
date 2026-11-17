<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\In\WordPress\LoginUrlRewriter;
use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Application\Service\RewriteLoginUrl;
use Pollora\Portcullis\Domain\Model\LoginSlug;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

describe('LoginUrlRewriter, which home it builds on', function (): void {
    beforeEach(function (): void {
        $this->rewriter = new LoginUrlRewriter(LoginSlug::fromString('acces-secret'), new RewriteLoginUrl, new WordPressHookRegistrar);
        Wp::$home = 'https://site.test';
        Wp::$networkHome = 'https://network.test';
        $this->login = 'https://site.test/wp/wp-login.php';
    });

    it('builds a site URL on the home of the site, also on a network', function (bool $multisite): void {
        Wp::$multisite = $multisite;

        expect($this->rewriter->filterSiteUrl($this->login, 'wp-login.php'))->toBe('https://site.test/acces-secret');
    })->with([false, true]);

    it('builds a redirect on the home of the site, also on a network', function (bool $multisite): void {
        Wp::$multisite = $multisite;

        expect($this->rewriter->filterRedirect('wp-login.php?checkemail=confirm'))->toBe('https://site.test/acces-secret?checkemail=confirm');
    })->with([false, true]);

    it('builds a network URL on the home of the network, only on a network', function (): void {
        Wp::$multisite = true;
        $network = $this->rewriter->filterNetworkSiteUrl($this->login, 'wp-login.php');
        Wp::$multisite = false;
        $single = $this->rewriter->filterNetworkSiteUrl($this->login, 'wp-login.php');

        expect($network)->toBe('https://network.test/acces-secret')
            ->and($single)->toBe('https://site.test/acces-secret');
    });

    it('resolves the home again for every URL, once a resolution has ended', function (): void {
        Wp::$options['home'] = 'https://stored.test';

        $first = $this->rewriter->filterSiteUrl($this->login, 'wp-login.php');
        $second = $this->rewriter->filterSiteUrl($this->login, 'wp-login.php');

        expect($first)->toBe('https://site.test/acces-secret')
            ->and($second)->toBe('https://site.test/acces-secret');
    });

    it('resolves the home again after a resolution that threw', function (): void {
        Wp::$options['home'] = 'https://stored.test';
        Wp::$onHomeUrl = static function (): void {
            Wp::$onHomeUrl = null;

            throw new RuntimeException('a filter failed');
        };

        expect(fn () => $this->rewriter->filterSiteUrl($this->login, 'wp-login.php'))->toThrow(RuntimeException::class);
        expect($this->rewriter->filterSiteUrl($this->login, 'wp-login.php'))->toBe('https://site.test/acces-secret');
    });
});
