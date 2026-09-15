<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\WpdbAttemptStore;
use Pollora\Portcullis\Adapter\Out\WordPress\WpdbSchema;
use Pollora\Portcullis\Port\Out\AttemptStorePort;

/*
 * Runs the AttemptStorePort contract against the MySQL adapter.
 *
 * The contract lives in tests/Contract; this file only supplies the adapter, on
 * a table prefix of its own.
 */

require_once __DIR__.'/bootstrap.php';

if (! defined('ABSPATH') || ! isset($GLOBALS['wpdb'])) {
    it('needs a WordPress installation', function (): void {})
        ->skip('Set PORTCULLIS_WP_LOAD to the wp-load.php of a WordPress installation.');

    return;
}

dataset('wpdb store', [
    'wpdb' => [function (): AttemptStorePort {
        $wpdb = $GLOBALS['wpdb'];

        $schema = new WpdbSchema($wpdb, WpdbSchema::TABLE.'_test');
        $schema->install();
        $wpdb->query('TRUNCATE TABLE '.$schema->table());

        return new WpdbAttemptStore($wpdb, $schema);
    }],
]);

require_once __DIR__.'/../Contract/AttemptStoreContract.php';

attemptStoreContract('wpdb store');

// Pest refuses afterAll() once WordPress has been loaded into the file's scope;
// a shutdown function cleans the test table up just as well.
register_shutdown_function(static function (): void {
    $wpdb = $GLOBALS['wpdb'] ?? null;

    if (is_object($wpdb) && method_exists($wpdb, 'query')) {
        $wpdb->query('DROP TABLE IF EXISTS '.$wpdb->base_prefix.WpdbSchema::TABLE.'_test');
    }
});
