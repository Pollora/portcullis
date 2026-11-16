<?php

declare(strict_types=1);

/*
 * ARCH-07: the harness preset (WordPress classes and functions, the tests), plus what is specific to this package.
 */
$configuration = require __DIR__.'/vendor/pollora/harness/presets/composer-dependency-analyser.php';

return $configuration
    // Pollora and Laravel are optional: PolloraHookRegistrar::isAvailable() checks class_exists() on the facades before using them, and they are absent when the package runs alone.
    ->ignoreUnknownClasses([
        'Illuminate\Support\Facades\Facade',
        'Pollora\Support\Facades\Action',
        'Pollora\Support\Facades\Filter',
    ]);
