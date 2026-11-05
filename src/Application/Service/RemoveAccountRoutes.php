<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

final class RemoveAccountRoutes
{
    /**
     * Hard-coded in `WP_REST_Users_Controller`, which exposes neither a getter nor a filter.
     *
     * @var list<string>
     */
    private const ACCOUNT_ROUTES = [
        '/wp/v2/users',
        '/wp/v2/users/(?P<id>[\d]+)',
    ];

    /**
     * @param  array<string, mixed>  $routes
     * @return array<string, mixed>
     */
    public function remove(array $routes): array
    {
        return array_diff_key($routes, array_flip(self::ACCOUNT_ROUTES));
    }
}
