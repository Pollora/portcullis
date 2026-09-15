<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\Out\WordPress;

use RuntimeException;
use wpdb;

/**
 * Creates and upgrades the table failure counters live in.
 *
 * A Composer package has no activation hook, so the schema is checked against a
 * version number stored as an autoloaded option — the check costs nothing once
 * WordPress has loaded its options — and brought up to date the first time it
 * lags behind. `wp portcullis install` does the same from a
 * deployment script.
 */
final class WpdbSchema
{
    /**
     * Current schema version. Bump it whenever {@see self::createTableStatement()} changes.
     */
    public const VERSION = 1;

    /**
     * Option the installed schema version is recorded in.
     *
     * A network option on multisite, since the table is shared by the network;
     * a regular, autoloaded option otherwise. `update_network_option()` cannot
     * be used for both: on a single site it stores the value with autoloading
     * turned off, which would cost a query on every request that checks it.
     */
    public const VERSION_OPTION = 'portcullis_schema_version';

    /**
     * Table name, without the database prefix.
     */
    public const TABLE = 'portcullis_attempts';

    /**
     * Seconds a failed installation is remembered before it is attempted again.
     *
     * Without it, a database user that may not create tables would run
     * `dbDelta()` on every login attempt and every administration screen. Long
     * enough to spare the database, short enough that fixing the permission
     * does not mean waiting; `wp portcullis install` never waits.
     */
    public const RETRY_DELAY = 900;

    /**
     * @param  wpdb  $wpdb  Database connection.
     * @param  string  $name  Table name, without the database prefix.
     */
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly string $name = self::TABLE,
    ) {}

    /**
     * Full table name.
     *
     * The base prefix is used so that a multisite network shares one table: an
     * address locked out on one site is locked out on all of them, which is what
     * a shared login screen calls for.
     */
    public function table(): string
    {
        return $this->wpdb->base_prefix.$this->name;
    }

    /**
     * Whether the installed schema is the current one.
     */
    public function isUpToDate(): bool
    {
        $installed = is_multisite()
            ? get_network_option(null, $this->versionOption(), 0)
            : get_option($this->versionOption(), 0);

        return (int) $installed === self::VERSION;
    }

    /**
     * Creates or upgrades the table when needed.
     *
     * A failure less than {@see self::RETRY_DELAY} seconds old is reported again
     * without a new attempt.
     *
     * @throws RuntimeException When the table cannot be created.
     */
    public function ensure(): void
    {
        if ($this->isUpToDate()) {
            return;
        }

        $error = $this->lastInstallError();

        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $this->install();
    }

    /**
     * Creates or upgrades the table, unconditionally.
     *
     * The outcome is remembered: a failure for {@see self::RETRY_DELAY} seconds,
     * so that {@see self::ensure()} and the administration notice can report it
     * without trying again; a success clears it.
     *
     * @throws RuntimeException When the table does not exist afterwards — typically
     *                          because the database user may not create tables.
     */
    public function install(): void
    {
        if (! function_exists('dbDelta')) {
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
        }

        dbDelta($this->createTableStatement());

        if (! $this->exists()) {
            $error = sprintf(
                'The table %s could not be created: %s',
                $this->table(),
                $this->wpdb->last_error !== '' ? $this->wpdb->last_error : 'check that the database user may create tables.'
            );

            set_site_transient($this->errorTransient(), $error, self::RETRY_DELAY);
            error_log('[portcullis] '.$error);

            throw new RuntimeException($error);
        }

        if (is_multisite()) {
            update_network_option(null, $this->versionOption(), self::VERSION);
        } else {
            update_option($this->versionOption(), self::VERSION, true);
        }

        delete_site_transient($this->errorTransient());
    }

    /**
     * The error of an installation that failed less than {@see self::RETRY_DELAY} seconds ago.
     */
    public function lastInstallError(): ?string
    {
        $error = get_site_transient($this->errorTransient());

        return is_string($error) && $error !== '' ? $error : null;
    }

    /**
     * Forgets the installed version, so that the next {@see self::ensure()} installs again.
     *
     * Called when the table turns out to be missing although its version is
     * recorded — a partial restore, a table dropped by hand. Without it, every
     * query would fail until someone ran `wp portcullis install`.
     */
    public function forget(): void
    {
        if (is_multisite()) {
            delete_network_option(null, $this->versionOption());
        } else {
            delete_option($this->versionOption());
        }
    }

    /**
     * Whether the table exists.
     */
    public function exists(): bool
    {
        $table = $this->table();

        return $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $this->wpdb->esc_like($table))) === $table;
    }

    /**
     * The `CREATE TABLE` statement, in the format `dbDelta()` expects.
     *
     * Every column is sized for the job, so that a flood of distinct addresses
     * stays cheap to store:
     *
     * - `(scope, subject)` is the primary key. One row per counter, whatever the
     *   number of attempts, and every lookup is a primary key lookup.
     * - `subject` is the raw 32-byte HMAC, never the hexadecimal form.
     * - timestamps are unsigned integers rather than `DATETIME`, which spares any
     *   time zone conversion between PHP and the database.
     * - `expires` serves the purge, `locked` serves the listing of lockouts.
     *
     * `dbDelta()` is picky: two spaces after `PRIMARY KEY`, one column per line.
     */
    public function createTableStatement(): string
    {
        return sprintf(
            "CREATE TABLE %s (\n"
            ."  scope tinyint(3) unsigned NOT NULL,\n"
            ."  subject binary(32) NOT NULL,\n"
            ."  failures smallint(5) unsigned NOT NULL DEFAULT 0,\n"
            ."  lockouts tinyint(3) unsigned NOT NULL DEFAULT 0,\n"
            ."  locked_until int(10) unsigned DEFAULT NULL,\n"
            ."  window_expires_at int(10) unsigned NOT NULL,\n"
            ."  last_failure_at int(10) unsigned NOT NULL,\n"
            ."  PRIMARY KEY  (scope,subject),\n"
            ."  KEY expires (window_expires_at),\n"
            ."  KEY locked (locked_until)\n"
            .') %s;',
            $this->table(),
            $this->wpdb->get_charset_collate()
        );
    }

    /**
     * Option the version of this table is recorded in.
     *
     * {@see self::VERSION_OPTION} for the default table, a name derived from the
     * table otherwise, so that two tables never share a version.
     */
    private function versionOption(): string
    {
        return $this->name === self::TABLE ? self::VERSION_OPTION : $this->name.'_schema_version';
    }

    /**
     * Site transient the last installation error is kept in.
     */
    private function errorTransient(): string
    {
        return $this->versionOption().'_error';
    }
}
