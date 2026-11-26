<?php

declare(strict_types=1);

/*
 * Minimal stand-ins for the WordPress functions and classes the adapters call,
 * loaded by Pest.php when no real installation is present.
 *
 * Every function carries WordPress' real signature and sits behind a
 * function_exists() guard; its state lives in Pollora\Portcullis\Tests\Support\Wp.
 * The hook functions are a small but faithful plugin API: priorities, accepted
 * argument counts and removal by callback all behave as in core, which is what
 * lets the guards be exercised through the same hooks they register in production.
 */

use Pollora\Portcullis\Tests\Support\Wp;

if (! defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string, list<string>> */
        public array $errors = [];

        /** @var array<string, mixed> */
        public array $error_data = [];

        public function __construct(string|int $code = '', string $message = '', mixed $data = '')
        {
            if ($code !== '') {
                $this->errors[(string) $code][] = $message;
                $this->error_data[(string) $code] = $data;
            }
        }

        /** @return list<string> */
        public function get_error_codes(): array
        {
            return array_keys($this->errors);
        }

        public function get_error_code(): string
        {
            return (string) (array_key_first($this->errors) ?? '');
        }

        public function get_error_message(string|int $code = ''): string
        {
            $code = $code === '' ? $this->get_error_code() : (string) $code;

            return $this->errors[$code][0] ?? '';
        }

        public function add(string|int $code, string $message, mixed $data = ''): void
        {
            $this->errors[(string) $code][] = $message;
        }

        public function remove(string|int $code): void
        {
            unset($this->errors[(string) $code]);
        }
    }
}

if (! class_exists('WP_User')) {
    class WP_User
    {
        public function __construct(public int $ID = 0, public string $user_login = '', public string $user_nicename = '') {}
    }
}

if (! class_exists('WP_Comment')) {
    class WP_Comment
    {
        public function __construct(public int|string $user_id = 0) {}
    }
}

if (! class_exists('WP_Rewrite')) {
    class WP_Rewrite
    {
        public function __construct(private bool $permalinks = false) {}

        /** Core rereads the permalink structure here; the fake has nothing to reread. */
        public function init(): void {}

        public function using_permalinks(): bool
        {
            return $this->permalinks;
        }
    }
}

if (! class_exists('WP_Query')) {
    class WP_Query
    {
        public int $post_count = 0;

        public bool $set404 = false;

        /** @param array<string, mixed> $vars */
        public function __construct(public bool $author = false, public array $vars = []) {}

        public function is_author(): bool
        {
            return $this->author;
        }

        public function get(string $name, mixed $default = ''): mixed
        {
            return $this->vars[$name] ?? $default;
        }

        public function set_404(): void
        {
            $this->set404 = true;
        }

        public function is_404(): bool
        {
            return $this->set404;
        }

        public function is_single(): bool
        {
            return false;
        }
    }
}

if (! class_exists('wpdb')) {
    /**
     * A wpdb that records the statements it is given and answers from a script.
     *
     * `prepare()` follows core for the placeholders the package uses: `%d` casts
     * to int, `%s` is quoted and escaped.
     */
    class wpdb
    {
        public string $base_prefix = 'wp_';

        public string $last_error = '';

        public int $num_queries = 0;

        /** @var list<string> */
        public array $queries = [];

        /** @var list<mixed> */
        public array $varResults = [];

        /** @var list<mixed> */
        public array $rowResults = [];

        /** @var list<mixed> */
        public array $listResults = [];

        /** @var list<int|bool> */
        public array $queryResults = [];

        public function prepare(string $query, mixed ...$args): string
        {
            $args = isset($args[0]) && is_array($args[0]) && count($args) === 1 ? $args[0] : $args;
            $query = str_replace(['%d', '%s'], ['%d', '%s'], $query);

            return (string) preg_replace_callback('/%([ds])/', static function (array $match) use (&$args): string {
                $value = array_shift($args);
                $value = is_scalar($value) ? $value : '';

                return $match[1] === 'd' ? (string) (int) $value : "'".addslashes((string) $value)."'";
            }, $query);
        }

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function hide_errors(): bool
        {
            return false;
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4';
        }

        public function query(string $query): int|bool
        {
            $this->queries[] = $query;

            return array_shift($this->queryResults) ?? 0;
        }

        public function get_var(string $query): mixed
        {
            $this->queries[] = $query;

            return array_shift($this->varResults);
        }

        public function get_row(string $query, string $output = OBJECT): mixed
        {
            $this->queries[] = $query;

            return array_shift($this->rowResults);
        }

        public function get_results(string $query, string $output = OBJECT): mixed
        {
            $this->queries[] = $query;

            return array_shift($this->listResults);
        }
    }
}

/*
 * Plugin API.
 */

if (! function_exists('add_filter')) {
    function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        Wp::$hooks[$hook_name][$priority][] = [$callback, $accepted_args];

        return true;
    }
}

if (! function_exists('add_action')) {
    function add_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return add_filter($hook_name, $callback, $priority, $accepted_args);
    }
}

if (! function_exists('remove_filter')) {
    /**
     * @param  callable|array{object|string, string}|string  $callback
     */
    function remove_filter(string $hook_name, callable|array|string $callback, int $priority = 10): bool
    {
        foreach (Wp::$hooks[$hook_name][$priority] ?? [] as $index => [$registered]) {
            if ($registered == $callback) {
                unset(Wp::$hooks[$hook_name][$priority][$index]);

                return true;
            }
        }

        return false;
    }
}

if (! function_exists('remove_action')) {
    /**
     * @param  callable|array{object|string, string}|string  $callback
     */
    function remove_action(string $hook_name, callable|array|string $callback, int $priority = 10): bool
    {
        return remove_filter($hook_name, $callback, $priority);
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
    {
        $byPriority = Wp::$hooks[$hook_name] ?? [];
        ksort($byPriority);

        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as [$callback, $accepted]) {
                $value = $callback(...array_slice([$value, ...$args], 0, $accepted));
            }
        }

        return $value;
    }
}

if (! function_exists('do_action')) {
    function do_action(string $hook_name, mixed ...$arg): void
    {
        Wp::$fired[$hook_name] = (Wp::$fired[$hook_name] ?? 0) + 1;

        $byPriority = Wp::$hooks[$hook_name] ?? [];
        ksort($byPriority);

        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as [$callback, $accepted]) {
                $callback(...array_slice($arg, 0, $accepted));
            }
        }
    }
}

if (! function_exists('did_action')) {
    function did_action(string $hook_name): int
    {
        return Wp::$fired[$hook_name] ?? 0;
    }
}

/*
 * Environment.
 */

if (! function_exists('home_url')) {
    function home_url(string $path = '', ?string $scheme = null): string
    {
        if (Wp::$onHomeUrl !== null) {
            (Wp::$onHomeUrl)();
        }

        return wpFakeUrl(Wp::$home, $path, $scheme);
    }
}

if (! function_exists('site_url')) {
    function site_url(string $path = '', ?string $scheme = null): string
    {
        return wpFakeUrl(Wp::$site, $path, $scheme);
    }
}

if (! function_exists('network_home_url')) {
    function network_home_url(string $path = '', ?string $scheme = null): string
    {
        return wpFakeUrl(Wp::$networkHome ?? Wp::$home, $path, $scheme);
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = '', string $scheme = 'admin'): string
    {
        return wpFakeUrl(Wp::$site.'/wp-admin', $path, null);
    }
}

if (! function_exists('wpFakeUrl')) {
    /** Test-only helper: joins a base URL and a path, `relative` dropping the host. */
    function wpFakeUrl(string $base, string $path, ?string $scheme): string
    {
        $url = rtrim($base, '/').'/'.ltrim($path, '/');

        if ($scheme === 'relative') {
            return (string) parse_url($url, PHP_URL_PATH);
        }

        return $path === '' ? $base : $url;
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default_value = false): mixed
    {
        return Wp::$options[$option] ?? $default_value;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, bool|string|null $autoload = null): bool
    {
        Wp::$options[$option] = $value;
        Wp::$autoload[$option] = $autoload;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        unset(Wp::$options[$option]);

        return true;
    }
}

if (! function_exists('get_network_option')) {
    function get_network_option(?int $network_id, string $option, mixed $default_value = false): mixed
    {
        return Wp::$networkOptions[$option] ?? $default_value;
    }
}

if (! function_exists('update_network_option')) {
    function update_network_option(?int $network_id, string $option, mixed $value): bool
    {
        Wp::$networkOptions[$option] = $value;

        return true;
    }
}

if (! function_exists('delete_network_option')) {
    function delete_network_option(?int $network_id, string $option): bool
    {
        unset(Wp::$networkOptions[$option]);

        return true;
    }
}

if (! function_exists('get_site_transient')) {
    function get_site_transient(string $transient): mixed
    {
        return Wp::$transients[$transient] ?? false;
    }
}

if (! function_exists('set_site_transient')) {
    function set_site_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        Wp::$transients[$transient] = $value;

        return true;
    }
}

if (! function_exists('delete_site_transient')) {
    function delete_site_transient(string $transient): bool
    {
        unset(Wp::$transients[$transient]);

        return true;
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return Wp::$multisite;
    }
}

if (! function_exists('is_admin')) {
    function is_admin(): bool
    {
        return Wp::$admin;
    }
}

if (! function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return Wp::$loggedIn;
    }
}

if (! function_exists('wp_doing_cron')) {
    function wp_doing_cron(): bool
    {
        return Wp::$doingCron;
    }
}

if (! function_exists('is_404')) {
    function is_404(): bool
    {
        return Wp::$is404;
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        return in_array($capability, Wp::$capabilities, true);
    }
}

if (! function_exists('get_userdata')) {
    function get_userdata(int|string $user_id): WP_User|false
    {
        return Wp::$usersById[(int) $user_id] ?? false;
    }
}

if (! function_exists('get_user_by')) {
    function get_user_by(string $field, int|string $value): WP_User|false
    {
        return match ($field) {
            'login' => Wp::$usersByLogin[$value] ?? false,
            'email' => Wp::$usersByEmail[$value] ?? false,
            default => false,
        };
    }
}

if (! function_exists('is_email')) {
    function is_email(string $email, bool $deprecated = false): string|false
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
    }
}

if (! function_exists('sanitize_html_class')) {
    function sanitize_html_class(string $classname, string $fallback_value = ''): string
    {
        $sanitized = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $classname);

        return $sanitized === '' ? $fallback_value : $sanitized;
    }
}

if (! function_exists('status_header')) {
    function status_header(int|string $code, string $description = ''): void
    {
        Wp::$statuses[] = (int) $code;
    }
}

if (! function_exists('nocache_headers')) {
    function nocache_headers(): void
    {
        Wp::$noCacheHeaders++;
    }
}

if (! function_exists('human_time_diff')) {
    function human_time_diff(int|string $from, int|string $to = 0): string
    {
        $seconds = abs((int) $to - (int) $from);

        return $seconds < 3600 ? ((int) round($seconds / 60)).' mins' : ((int) round($seconds / 3600)).' hours';
    }
}

if (! function_exists('wp_next_scheduled')) {
    /**
     * @param  list<mixed>  $args
     */
    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        return Wp::$scheduled[$hook] ?? false;
    }
}

if (! function_exists('wp_schedule_event')) {
    /**
     * @param  list<mixed>  $args
     */
    function wp_schedule_event(int|string $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false): bool
    {
        Wp::$scheduleCalls[] = [(int) $timestamp, (string) $recurrence, (string) $hook];
        Wp::$scheduled[$hook] = (int) $timestamp;

        return true;
    }
}

if (! function_exists('get_page_by_path')) {
    /**
     * @param  list<string>|string  $post_type
     */
    function get_page_by_path(string $page_path, string $output = OBJECT, array|string $post_type = 'page'): ?stdClass
    {
        Wp::$pageLookups[] = [(string) $page_path, $output, (array) $post_type];

        return Wp::$pages[$page_path] ?? null;
    }
}

if (! function_exists('get_post_types')) {
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, string>
     */
    function get_post_types(array $args = [], string $output = 'names', string $operator = 'and'): array
    {
        // Only the public post types are known to the fake installation.
        if (($args['public'] ?? null) !== true) {
            return [];
        }

        return array_combine(Wp::$publicPostTypes, Wp::$publicPostTypes);
    }
}

if (! function_exists('dbDelta')) {
    /**
     * @param  list<string>|string  $queries
     * @return array<string, string>
     */
    function dbDelta(array|string $queries = '', bool $execute = true): array
    {
        Wp::$dbDelta[] = is_array($queries) ? implode(";\n", $queries) : $queries;

        return [];
    }
}

/*
 * Translation and escaping: identity-like, which is all the adapters need.
 */

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string|int|float $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }
}
