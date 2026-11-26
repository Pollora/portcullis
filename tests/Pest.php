<?php

declare(strict_types=1);
use Pollora\Portcullis\Tests\Support\Wp;

/*
 * The Domain and Application layers are free of WordPress calls by design, so
 * the unit suite runs without bootstrapping WordPress and without stubs.
 */

uses()->in('Unit', 'Integration');

/*
 * The adapters call WordPress directly. Without an installation (the usual
 * case: the integration suite is the one that needs a real one), tests/Support
 * provides stand-ins, and every test starts from a pristine fake installation.
 *
 * Under a real installation the stand-ins stay unloaded — they would clash with
 * core — and the unit tests that need them skip themselves.
 */

$portcullisRealWordPress = is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '';

if (! $portcullisRealWordPress) {
    require_once __DIR__.'/Support/wordpress-fakes.php';
    require_once __DIR__.'/Support/wp-cli-fakes.php';

    uses()->beforeEach(function (): void {
        Wp::reset();
        WP_CLI::reset();
    })->in('Unit');
}

require_once __DIR__.'/Support/php-subprocess.php';
require_once __DIR__.'/Support/helpers.php';
