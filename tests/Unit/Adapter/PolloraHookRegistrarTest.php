<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Pollora\Portcullis\Adapter\Out\Pollora\PolloraHookRegistrar;
use Pollora\Support\Facades\Action;
use Pollora\Support\Facades\Filter;

require_once __DIR__.'/../../Support/pollora-fakes.php';

beforeEach(function (): void {
    Facade::$app = null;
    Action::$calls = [];
    Filter::$calls = [];
});

afterEach(function (): void {
    Facade::$app = null;
});

it('is not available while the container behind the facades is not set', function (): void {
    // Composer's autoloader runs long before any application boots: calling a
    // facade in that state would throw.
    expect(PolloraHookRegistrar::isAvailable())->toBeFalse();
});

it('is available once the application is set', function (): void {
    Facade::$app = new stdClass;

    expect(PolloraHookRegistrar::isAvailable())->toBeTrue();
});

it('sends actions through the framework\'s Action facade', function (): void {
    $registrar = new PolloraHookRegistrar;
    $callback = static fn () => null;

    $registrar->addAction('init', $callback, 7, 2);
    $registrar->removeAction('init', 'some_function', 7);
    $registrar->doAction('portcullis/locked_out', 'a', 'b');

    expect(Action::$calls)->toBe([
        ['add', ['init', $callback, 7, 2]],
        ['remove', ['init', 'some_function', 7]],
        ['do', ['portcullis/locked_out', 'a', 'b']],
    ]);
});

it('sends filters through the framework\'s Filter facade', function (): void {
    $registrar = new PolloraHookRegistrar;
    $callback = static fn () => null;

    $registrar->addFilter('site_url', $callback, 3, 4);
    $result = $registrar->applyFilters('portcullis/author_link', 'value', 'extra');

    expect(Filter::$calls)->toBe([
        ['add', ['site_url', $callback, 3, 4]],
        ['apply', ['portcullis/author_link', 'value', 'extra']],
    ])->and($result)->toBe('filtered:value');
});
