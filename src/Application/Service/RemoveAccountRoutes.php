<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

/**
 * Removes the REST routes that list accounts. They are removed rather than
 * refused, so that the answer is the one an installation without them gives.
 */
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
     * Drops `/wp/v2/users` and `/wp/v2/users/{id}` from the route map.
     *
     * @param  array<string, mixed>  $routes  Registered REST routes, keyed by pattern.
     * @return array<string, mixed> The same routes without the two account routes.
     */
    public function remove(array $routes): array
    {
        return array_diff_key($routes, array_flip(self::ACCOUNT_ROUTES));
    }
}
