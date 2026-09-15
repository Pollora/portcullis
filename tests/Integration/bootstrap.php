<?php

declare(strict_types=1);

/*
 * Loads a real WordPress installation for the integration suite.
 *
 * Point PORTCULLIS_WP_LOAD at its wp-load.php; the suite is skipped otherwise.
 * The adapters are exercised against a dedicated table prefix, so the
 * installation's own counters are never touched.
 *
 *     PORTCULLIS_WP_LOAD=/var/www/html/web/wp/wp-load.php vendor/bin/pest --testsuite=Integration
 */

$wpLoad = getenv('PORTCULLIS_WP_LOAD');

if (! is_string($wpLoad) || $wpLoad === '' || defined('ABSPATH')) {
    return;
}

if (! is_file($wpLoad)) {
    throw new RuntimeException("PORTCULLIS_WP_LOAD does not point to a file: {$wpLoad}");
}

define('WP_USE_THEMES', false);

$_SERVER['HTTP_HOST'] ??= 'localhost';
$_SERVER['REQUEST_URI'] ??= '/';

// Regular plugins stay out: they are irrelevant to the adapters under test, and
// some ship their own development dependencies — PHPUnit included — which would
// clash with the test runner's. The callback is seeded into the pre-initialised
// hook array, the only way to filter anything before WordPress loads.
$GLOBALS['wp_filter']['option_active_plugins'][PHP_INT_MAX][] = [
    'function' => static fn (): array => [],
    'accepted_args' => 1,
];

// wp-settings.php expects to run at the top level of a script; these globals
// are the ones it relies on being shared.
global $wpdb, $wp_version, $wp_filter, $wp_actions, $wp_current_filter, $table_prefix;

require_once $wpLoad;
