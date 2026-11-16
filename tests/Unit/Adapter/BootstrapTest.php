<?php

declare(strict_types=1);

use Pollora\Portcullis\Bootstrap;
use Pollora\Portcullis\Portcullis;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

function forgetBoot(): void
{
    (new ReflectionProperty(Portcullis::class, 'booted'))->setValue(null, false);
}

beforeEach(function (): void {
    forgetBoot();
});

afterEach(function (): void {
    forgetBoot();
});

it('defers the boot to muplugins_loaded when WordPress has not reached it yet', function (): void {
    Bootstrap::schedule();

    expect(Wp::$hooks['muplugins_loaded'])->toHaveKey(0)
        ->and(Wp::callbacks('muplugins_loaded'))->toHaveCount(1)
        ->and(Wp::callbacks('muplugins_loaded')[0][0])->toBe([Portcullis::class, 'boot'])
        // Nothing is registered until the hook fires.
        ->and(Wp::callbacks('rest_endpoints'))->toBe([]);
});

it('boots at once when muplugins_loaded has already fired, or the package would never register', function (): void {
    do_action('muplugins_loaded');

    Bootstrap::schedule();

    expect(Wp::callbacks('rest_endpoints'))->toHaveCount(1)
        ->and(Wp::callbacks('muplugins_loaded'))->toBe([]);
});

it('boots when the deferred callback runs', function (): void {
    Bootstrap::schedule();

    do_action('muplugins_loaded');

    expect(Wp::callbacks('rest_endpoints'))->toHaveCount(1);
});
