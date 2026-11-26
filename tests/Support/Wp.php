<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Tests\Support;

/**
 * State behind the WordPress function stubs of `wordpress-fakes.php`.
 *
 * The stubs are process-wide (a PHP function cannot be undefined), so every
 * piece of state they read lives here, where {@see self::reset()} puts it back
 * to a pristine installation before each test.
 */
final class Wp
{
    /** @var array<string, array<int, array<int, array{callable, int}>>> Keyed by priority; removal leaves gaps. */
    public static array $hooks = [];

    /** @var array<string, int> */
    public static array $fired = [];

    /** @var array<string, mixed> */
    public static array $options = [];

    /** @var array<string, mixed> */
    public static array $networkOptions = [];

    /** @var array<string, mixed> */
    public static array $transients = [];

    public static bool $multisite = false;

    public static bool $admin = false;

    public static bool $loggedIn = false;

    public static bool $doingCron = false;

    public static bool $is404 = false;

    /** @var list<string> */
    public static array $capabilities = [];

    /** @var (callable(): mixed)|null Runs inside home_url(), to simulate a filter that re-enters it. */
    public static $onHomeUrl = null;

    public static string $home = 'https://example.test';

    public static string $site = 'https://example.test/wp';

    /** @var string|null Home of the network, the home of the site when null. */
    public static ?string $networkHome = null;

    /** @var array<string, \WP_User> */
    public static array $usersByLogin = [];

    /** @var array<string, \WP_User> */
    public static array $usersByEmail = [];

    /** @var array<int, \WP_User> */
    public static array $usersById = [];

    /** @var list<int> */
    public static array $statuses = [];

    public static int $noCacheHeaders = 0;

    /** @var array<string, int|false> */
    public static array $scheduled = [];

    /** @var list<array{int, string, string}> */
    public static array $scheduleCalls = [];

    /** @var array<string, \stdClass> */
    public static array $pages = [];

    /** @var list<array{string, mixed, list<string>}> */
    public static array $pageLookups = [];

    /** @var list<string> */
    public static array $publicPostTypes = ['post', 'page'];

    /** @var list<string> */
    public static array $dbDelta = [];

    /** @var array<string, mixed> Autoload flag passed along with each option written by update_option(). */
    public static array $autoload = [];

    public static function reset(): void
    {
        self::$hooks = [];
        self::$fired = [];
        self::$options = [];
        self::$networkOptions = [];
        self::$transients = [];
        self::$multisite = false;
        self::$admin = false;
        self::$loggedIn = false;
        self::$doingCron = false;
        self::$is404 = false;
        self::$capabilities = [];
        self::$onHomeUrl = null;
        self::$home = 'https://example.test';
        self::$site = 'https://example.test/wp';
        self::$networkHome = null;
        self::$usersByLogin = [];
        self::$usersByEmail = [];
        self::$usersById = [];
        self::$statuses = [];
        self::$noCacheHeaders = 0;
        self::$scheduled = [];
        self::$scheduleCalls = [];
        self::$pages = [];
        self::$pageLookups = [];
        self::$publicPostTypes = ['post', 'page'];
        self::$dbDelta = [];
        self::$autoload = [];

        unset($GLOBALS['pagenow'], $GLOBALS['wp_rewrite'], $GLOBALS['wpdb']);
    }

    /**
     * Whether a callback is registered on a hook, at a priority when given.
     */
    public static function hasHook(string $hook, callable|string $callback, ?int $priority = null): bool
    {
        foreach (self::$hooks[$hook] ?? [] as $at => $callbacks) {
            foreach ($callbacks as [$registered]) {
                if ($registered == $callback && ($priority === null || $priority === $at)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every callback of a hook, in the order WordPress would run them.
     *
     * @return list<array{callable, int}>
     */
    public static function callbacks(string $hook): array
    {
        $byPriority = self::$hooks[$hook] ?? [];
        ksort($byPriority);

        return array_merge(...array_values($byPriority ?: [[]]));
    }
}
