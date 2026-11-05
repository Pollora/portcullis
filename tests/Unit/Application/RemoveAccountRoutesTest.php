<?php

declare(strict_types=1);

use Pollora\Portcullis\Application\Service\RemoveAccountRoutes;

beforeEach(function (): void {
    $this->routes = [
        '/' => ['index'],
        '/wp/v2/posts' => ['posts'],
        '/wp/v2/users' => ['list'],
        '/wp/v2/users/(?P<id>[\d]+)' => ['single'],
        '/wp/v2/users/me' => ['me'],
        '/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords' => ['application passwords'],
    ];
});

it('removes the routes that list accounts', function (): void {
    $kept = array_keys((new RemoveAccountRoutes)->remove($this->routes));

    expect($kept)->not()->toContain('/wp/v2/users')
        ->and($kept)->not()->toContain('/wp/v2/users/(?P<id>[\d]+)');
});

it('keeps every other route untouched', function (): void {
    expect((new RemoveAccountRoutes)->remove($this->routes))->toBe([
        '/' => ['index'],
        '/wp/v2/posts' => ['posts'],
        '/wp/v2/users/me' => ['me'],
        '/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords' => ['application passwords'],
    ]);
});
