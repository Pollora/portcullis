<?php

declare(strict_types=1);

use Pollora\Portcullis\Application\Service\MatchHiddenLoginRequest;
use Pollora\Portcullis\Domain\Model\LoginSlug;

beforeEach(function (): void {
    $this->matcher = new MatchHiddenLoginRequest;
    $this->slug = LoginSlug::fromString('acces-prive');
});

it('matches the slug whatever the trailing slash and the query string', function (string $uri): void {
    expect($this->matcher->matches($uri, '', $this->slug))->toBeTrue();
})->with([
    '/acces-prive',
    '/acces-prive/',
    '/acces-prive?action=lostpassword',
    '/acces-prive/?action=rp&key=abc&login=admin',
    '/index.php/acces-prive',
]);

it('does not match anything else', function (string $uri): void {
    expect($this->matcher->matches($uri, '', $this->slug))->toBeFalse();
})->with([
    '/',
    '/acces-prive/wp-login.php',
    '/acces-privee',
    '/wp/wp-login.php',
    '/wp/wp-admin/',
    '/blog/acces-prive',
]);

it('matches below the home path on a subdirectory installation', function (): void {
    expect($this->matcher->matches('/blog/acces-prive', 'blog', $this->slug))->toBeTrue()
        ->and($this->matcher->matches('/acces-prive', 'blog', $this->slug))->toBeFalse();
});

it('does not match a request whose path carries an encoded line break', function (string $uri): void {
    // %0A decodes to "\n", which neither the slug nor the path normalisation
    // may treat as part of the slug: such a request must not reveal the form.
    expect($this->matcher->matches($uri, '', $this->slug))->toBeFalse();
})->with([
    '/acces-prive%0A',
    '/acces-prive%0A/',
    '/acces-prive%0a?action=lostpassword',
    '/%0Aacces-prive',
]);
