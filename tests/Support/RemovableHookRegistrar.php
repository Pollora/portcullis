<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Tests\Support;

use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;

/**
 * The plugin API, with a way to take back every callback registered through it,
 * so that integration tests leave the installation as they found it.
 */
final class RemovableHookRegistrar implements HookRegistrarPort
{
    /**
     * @var list<array{string, callable, int}>
     */
    private array $registered = [];

    private readonly WordPressHookRegistrar $hooks;

    public function __construct()
    {
        $this->hooks = new WordPressHookRegistrar;
    }

    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->registered[] = [$hook, $callback, $priority];
        $this->hooks->addAction($hook, $callback, $priority, $acceptedArgs);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->registered[] = [$hook, $callback, $priority];
        $this->hooks->addFilter($hook, $callback, $priority, $acceptedArgs);
    }

    public function removeAction(string $hook, callable|string $callback, int $priority = 10): void
    {
        $this->hooks->removeAction($hook, $callback, $priority);
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        $this->hooks->doAction($hook, ...$args);
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $this->hooks->applyFilters($hook, $value, ...$args);
    }

    public function removeAll(): void
    {
        foreach ($this->registered as [$hook, $callback, $priority]) {
            remove_filter($hook, $callback, $priority);
        }

        $this->registered = [];
    }
}
