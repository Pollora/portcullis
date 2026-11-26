<?php

declare(strict_types=1);

/*
 * The slice of Pollora and Laravel that PolloraHookRegistrar talks to: the
 * facade base class and the Action and Filter facades, recording their calls.
 *
 * Loaded on demand by the tests that need it. The facade application stays
 * unset until a test sets it, so the classes being present never makes the
 * package believe it runs on Pollora.
 */

namespace Illuminate\Support\Facades {
    if (! class_exists(Facade::class)) {
        class Facade
        {
            public static ?object $app = null;

            public static function getFacadeApplication(): ?object
            {
                return self::$app;
            }
        }
    }
}

namespace Pollora\Support\Facades {
    if (! class_exists(Action::class)) {
        class Action
        {
            /** @var list<array{string, array<array-key, mixed>}> */
            public static array $calls = [];

            public static function add(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
            {
                self::$calls[] = ['add', [$hook, $callback, $priority, $acceptedArgs]];
            }

            public static function remove(string $hook, callable|string $callback, int $priority = 10): void
            {
                self::$calls[] = ['remove', [$hook, $callback, $priority]];
            }

            public static function do(string $hook, mixed ...$args): void
            {
                self::$calls[] = ['do', [$hook, ...$args]];
            }
        }
    }

    if (! class_exists(Filter::class)) {
        class Filter
        {
            /** @var list<array{string, array<array-key, mixed>}> */
            public static array $calls = [];

            public static function add(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
            {
                self::$calls[] = ['add', [$hook, $callback, $priority, $acceptedArgs]];
            }

            public static function apply(string $hook, mixed $value, mixed ...$args): mixed
            {
                self::$calls[] = ['apply', [$hook, $value, ...$args]];

                return 'filtered:'.$value;
            }
        }
    }
}
