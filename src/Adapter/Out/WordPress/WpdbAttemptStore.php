<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\Out\WordPress;

use Pollora\Portcullis\Domain\Model\AttemptState;
use Pollora\Portcullis\Domain\Model\Lockout;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Port\Out\AttemptStorePort;
use RuntimeException;
use wpdb;

/**
 * Keeps failure counters in a dedicated MySQL/MariaDB table.
 *
 * Chosen over transients on purpose. Transients land in `wp_options` unless a
 * persistent object cache is configured, two rows per key, with no index a
 * purge could use — an attack would bloat the one table every request reads
 * from. A dedicated table holds one compact row per counter, found by primary
 * key.
 *
 * Atomicity comes from the database rather than from PHP: every increment is a
 * single `INSERT … ON DUPLICATE KEY UPDATE`, and every lockout a conditional
 * `UPDATE` whose affected-row count says whether it applied.
 *
 * Subjects travel as hexadecimal and are converted with `UNHEX()` / `HEX()` in
 * SQL. Binary strings cannot be passed through `$wpdb->prepare()` safely:
 * `$wpdb->query()` strips byte sequences that are not valid in the connection
 * charset from any query that is not pure ASCII, which would corrupt the keys.
 */
final class WpdbAttemptStore implements AttemptStorePort
{
    /**
     * Whether the schema has been checked in this request.
     */
    private bool $ready = false;

    /**
     * @param  wpdb  $wpdb  Database connection.
     * @param  WpdbSchema  $schema  Table the counters live in.
     */
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly WpdbSchema $schema,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function lockedUntil(array $subjects, int $now): ?int
    {
        $this->ready();

        if ($subjects === []) {
            return null;
        }

        [$where, $args] = $this->subjectsClause($subjects);

        $value = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT MAX(locked_until) FROM {$this->schema->table()} WHERE ({$where}) AND locked_until > %d",
            ...[...$args, $now]
        ));

        $this->failOnError();

        return $value === null ? null : (int) $value;
    }

    /**
     * {@inheritDoc}
     *
     * MySQL evaluates the assignments of `ON DUPLICATE KEY UPDATE` from left to
     * right, each one seeing the columns already updated. `window_expires_at` is
     * therefore assigned last, so that the two tests before it read the window
     * as it was before this failure.
     */
    public function recordFailure(Subject $subject, int $now, int $windowExpiresAt): AttemptState
    {
        $this->ready();

        $table = $this->schema->table();

        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$table} (scope, subject, failures, lockouts, locked_until, window_expires_at, last_failure_at)
            VALUES (%d, UNHEX(%s), 1, 0, NULL, %d, %d)
            ON DUPLICATE KEY UPDATE
                failures = IF(window_expires_at < %d, 1, LEAST(failures + 1, 65535)),
                lockouts = IF(window_expires_at < %d, 0, lockouts),
                last_failure_at = %d,
                window_expires_at = %d",
            $subject->scope()->value,
            $subject->hexKey(),
            $windowExpiresAt,
            $now,
            $now,
            $now,
            $now,
            $windowExpiresAt,
        ));

        $this->failOnError();

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT failures, lockouts, locked_until, window_expires_at FROM {$table} WHERE scope = %d AND subject = UNHEX(%s)",
            $subject->scope()->value,
            $subject->hexKey(),
        ), ARRAY_A);

        $this->failOnError();

        if (! is_array($row)) {
            // Only possible if a concurrent clear() deleted the row in between: the
            // failure has been forgotten along with the rest, report it as such.
            return new AttemptState(0, 0, null, $windowExpiresAt);
        }

        return new AttemptState(
            (int) $row['failures'],
            (int) $row['lockouts'],
            $row['locked_until'] === null ? null : (int) $row['locked_until'],
            (int) $row['window_expires_at'],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function lock(Subject $subject, int $lockedUntil, int $threshold, bool $long): bool
    {
        $this->ready();

        $affected = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->schema->table()}
            SET failures = 0, lockouts = IF(%d = 1, 0, LEAST(lockouts + 1, 255)), locked_until = %d
            WHERE scope = %d AND subject = UNHEX(%s) AND failures >= %d",
            $long ? 1 : 0,
            $lockedUntil,
            $subject->scope()->value,
            $subject->hexKey(),
            $threshold,
        ));

        $this->failOnError();

        return $affected === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(array $subjects): void
    {
        $this->ready();

        if ($subjects === []) {
            return;
        }

        [$where, $args] = $this->subjectsClause($subjects);

        $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$this->schema->table()} WHERE {$where}", ...$args));

        $this->failOnError();
    }

    /**
     * {@inheritDoc}
     */
    public function purgeExpired(int $now, int $limit): int
    {
        $this->ready();

        $affected = $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->schema->table()}
            WHERE window_expires_at < %d AND (locked_until IS NULL OR locked_until <= %d)
            LIMIT %d",
            $now,
            $now,
            max(1, $limit),
        ));

        $this->failOnError();

        return is_int($affected) ? $affected : 0;
    }

    /**
     * {@inheritDoc}
     */
    public function activeLockouts(int $now, int $limit): array
    {
        $this->ready();

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT scope, LOWER(HEX(subject)) AS subject, locked_until FROM {$this->schema->table()}
            WHERE locked_until > %d
            ORDER BY locked_until DESC
            LIMIT %d",
            $now,
            max(1, $limit),
        ), ARRAY_A);

        $this->failOnError();

        $lockouts = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $scope = SubjectScope::tryFrom((int) $row['scope']);

            if ($scope === null) {
                continue;
            }

            $lockouts[] = new Lockout(Subject::fromHex($scope, (string) $row['subject']), (int) $row['locked_until'], false);
        }

        return $lockouts;
    }

    /**
     * Brings the table up to date before its first use in the request.
     *
     * @throws RuntimeException When the table cannot be created.
     */
    private function ready(): void
    {
        if ($this->ready) {
            return;
        }

        $this->schema->ensure();
        $this->ready = true;
    }

    /**
     * Builds `(scope = %d AND subject = UNHEX(%s)) OR …` with its arguments.
     *
     * @param  list<Subject>  $subjects  Subjects to match.
     * @return array{0: string, 1: list<int|string>}
     */
    private function subjectsClause(array $subjects): array
    {
        $clauses = [];
        $args = [];

        foreach ($subjects as $subject) {
            $clauses[] = '(scope = %d AND subject = UNHEX(%s))';
            $args[] = $subject->scope()->value;
            $args[] = $subject->hexKey();
        }

        return [implode(' OR ', $clauses), $args];
    }

    /**
     * Turns a database error into an exception.
     *
     * `$wpdb` only reports errors through `last_error`. Carrying on would make a
     * missing table look like "no lockout, no failure", which silently disables
     * the protection; the guard catches the exception and decides what to do.
     *
     * @throws RuntimeException When the last query failed.
     */
    private function failOnError(): void
    {
        if ($this->wpdb->last_error !== '') {
            throw new RuntimeException('[portcullis] '.$this->wpdb->last_error);
        }
    }
}
