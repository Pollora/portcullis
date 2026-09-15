<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\WpdbAttemptStore;
use Pollora\Portcullis\Adapter\Out\WordPress\WpdbSchema;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;

/*
 * Installation, self-healing and failure memory of the attempts table, on a
 * real WordPress installation. Every test works on a table of its own.
 */

require_once __DIR__.'/bootstrap.php';

if (! defined('ABSPATH') || ! isset($GLOBALS['wpdb'])) {
    it('needs a WordPress installation', function (): void {})
        ->skip('Set PORTCULLIS_WP_LOAD to the wp-load.php of a WordPress installation.');

    return;
}

function schemaFor(string $name): WpdbSchema
{
    $wpdb = $GLOBALS['wpdb'];
    $wpdb->query('DROP TABLE IF EXISTS '.$wpdb->base_prefix.$name);
    delete_option($name.'_schema_version');
    delete_site_transient($name.'_schema_version_error');

    return new WpdbSchema($wpdb, $name);
}

function schemaSubject(): Subject
{
    return new Subject(SubjectScope::Ip, hash('sha256', 'schema-test', true));
}

it('installs the table on first use and records its version', function (): void {
    $schema = schemaFor('portcullis_schema_test_install');

    expect($schema->exists())->toBeFalse()
        ->and($schema->isUpToDate())->toBeFalse();

    (new WpdbAttemptStore($GLOBALS['wpdb'], $schema))->recordFailure(schemaSubject(), 1000, 2000);

    expect($schema->exists())->toBeTrue()
        ->and($schema->isUpToDate())->toBeTrue()
        ->and($schema->lastInstallError())->toBeNull();
});

it('reinstalls a table dropped while its version is still recorded', function (): void {
    $schema = schemaFor('portcullis_schema_test_heal');
    $schema->install();
    $GLOBALS['wpdb']->query('DROP TABLE '.$schema->table());

    $store = new WpdbAttemptStore($GLOBALS['wpdb'], $schema);

    // The first query hits the missing table: it fails, but forgets the version…
    expect(fn () => $store->lockedUntil([schemaSubject()], 1000))->toThrow(RuntimeException::class)
        ->and($schema->isUpToDate())->toBeFalse();

    // …so the next one installs the table again and succeeds.
    expect($store->lockedUntil([schemaSubject()], 1000))->toBeNull()
        ->and($schema->exists())->toBeTrue();
});

it('remembers a failed installation instead of retrying on every request', function (): void {
    // A name the database rejects stands in for a user that may not create tables.
    $schema = schemaFor('portcullis schema test failure');

    expect(fn () => $schema->ensure())->toThrow(RuntimeException::class);

    $error = $schema->lastInstallError();

    expect($error)->toContain('could not be created');

    $queries = $GLOBALS['wpdb']->num_queries;

    expect(fn () => $schema->ensure())->toThrow(RuntimeException::class, (string) $error)
        // Only the version check: no second dbDelta() within the retry delay.
        ->and($GLOBALS['wpdb']->num_queries - $queries)->toBeLessThanOrEqual(2);

    delete_site_transient('portcullis schema test failure_schema_version_error');
});

register_shutdown_function(static function (): void {
    $wpdb = $GLOBALS['wpdb'] ?? null;

    if (! is_object($wpdb) || ! method_exists($wpdb, 'query')) {
        return;
    }

    foreach (['portcullis_schema_test_install', 'portcullis_schema_test_heal'] as $name) {
        $wpdb->query('DROP TABLE IF EXISTS '.$wpdb->base_prefix.$name);
        delete_option($name.'_schema_version');
    }
});
