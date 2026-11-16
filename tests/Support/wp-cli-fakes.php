<?php

declare(strict_types=1);

/*
 * A WP-CLI that records what the command prints instead of printing it.
 *
 * WP_CLI::error() ends the process in the real thing; here it throws, which is
 * how a test observes "the command stopped with this message".
 */

namespace WP_CLI\Utils {
    if (! function_exists(__NAMESPACE__.'\format_items')) {
        /**
         * @param  array<int, array<string, mixed>>  $items
         * @param  list<string>  $fields
         */
        function format_items($format, $items, $fields): void
        {
            \WP_CLI::$tables[] = ['format' => $format, 'items' => $items, 'fields' => $fields];
        }
    }
}

namespace Pollora\Portcullis\Tests\Support {
    use RuntimeException;

    /**
     * Thrown by the fake WP_CLI::error(), where the real one exits.
     */
    final class CliHalt extends RuntimeException {}
}

namespace {
    use Pollora\Portcullis\Tests\Support\CliHalt;

    if (! class_exists('WP_CLI')) {
        class WP_CLI
        {
            /** @var list<string> */
            public static array $lines = [];

            /** @var list<string> */
            public static array $successes = [];

            /** @var list<string> */
            public static array $warnings = [];

            /** @var array<string, object> */
            public static array $commands = [];

            /** @var list<array{format: mixed, items: array<int, array<string, mixed>>, fields: list<string>}> */
            public static array $tables = [];

            public static function reset(): void
            {
                self::$lines = [];
                self::$successes = [];
                self::$warnings = [];
                self::$commands = [];
                self::$tables = [];
            }

            public static function line($message = ''): void
            {
                self::$lines[] = (string) $message;
            }

            public static function success($message): void
            {
                self::$successes[] = (string) $message;
            }

            public static function warning($message): void
            {
                self::$warnings[] = (string) $message;
            }

            public static function error($message, $exit = true): void
            {
                throw new CliHalt((string) $message);
            }

            public static function add_command($name, $callable, $args = []): void
            {
                self::$commands[(string) $name] = $callable;
            }
        }
    }
}
