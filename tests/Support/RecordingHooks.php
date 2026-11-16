<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Tests\Support;

use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;

/**
 * The plugin API, remembering which callbacks were asked to be removed.
 *
 * Core callbacks are removed by name, and the fake installation does not
 * register them: what matters to the guards is that the removal was requested.
 */
final class RecordingHooks implements HookRegistrarPort
{
    /** @var list<array{string, callable|string, int}> */
    public array $removed = [];

    private readonly WordPressHookRegistrar $inner;

    public function __construct()
    {
        $this->inner = new WordPressHookRegistrar;
    }

    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->inner->addAction($hook, $callback, $priority, $acceptedArgs);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->inner->addFilter($hook, $callback, $priority, $acceptedArgs);
    }

    public function removeAction(string $hook, callable|string $callback, int $priority = 10): void
    {
        $this->removed[] = [$hook, $callback, $priority];
        $this->inner->removeAction($hook, $callback, $priority);
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        $this->inner->doAction($hook, ...$args);
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $this->inner->applyFilters($hook, $value, ...$args);
    }

    /**
     * Whether the removal of a callback was requested.
     */
    public function removedCallback(string $hook, string $callback): bool
    {
        foreach ($this->removed as [$removedHook, $removedCallback]) {
            if ($removedHook === $hook && $removedCallback === $callback) {
                return true;
            }
        }

        return false;
    }
}
