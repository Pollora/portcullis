<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\In\WordPress\AttemptPurgeScheduler;
use Pollora\Portcullis\Adapter\In\WordPress\SlugCollisionNotice;
use Pollora\Portcullis\Adapter\In\WordPress\StorageHealthNotice;
use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Adapter\Out\WordPress\WpdbAttemptStore;
use Pollora\Portcullis\Adapter\Out\WordPress\WpdbSchema;
use Pollora\Portcullis\Domain\Model\LoginSlug;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Tests\Support\CountingPurgeStore;
use Pollora\Portcullis\Tests\Support\ExplodingAttemptStore;
use Pollora\Portcullis\Tests\Support\FrozenClock;
use Pollora\Portcullis\Tests\Support\InMemoryAttemptStore;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

function installedSchema(wpdb $wpdb): WpdbSchema
{
    Wp::$options[WpdbSchema::VERSION_OPTION] = WpdbSchema::VERSION;

    return new WpdbSchema($wpdb);
}

function subjectOf(string $seed, SubjectScope $scope = SubjectScope::Ip): Subject
{
    return new Subject($scope, hash('sha256', $seed, true));
}

describe('WpdbSchema', function (): void {
    it('names the table after the base prefix', function (): void {
        $wpdb = new wpdb;
        $wpdb->base_prefix = 'bedrock_';

        expect((new WpdbSchema($wpdb))->table())->toBe('bedrock_portcullis_attempts');
    });

    it('is up to date only at the current schema version', function (): void {
        $schema = new WpdbSchema(new wpdb);

        expect($schema->isUpToDate())->toBeFalse();

        Wp::$options[WpdbSchema::VERSION_OPTION] = WpdbSchema::VERSION - 1;
        expect($schema->isUpToDate())->toBeFalse();

        Wp::$options[WpdbSchema::VERSION_OPTION] = (string) WpdbSchema::VERSION;
        expect($schema->isUpToDate())->toBeTrue();
    });

    it('keeps its version on the network on a multisite', function (): void {
        Wp::$multisite = true;
        $schema = new WpdbSchema(new wpdb);

        Wp::$options[WpdbSchema::VERSION_OPTION] = WpdbSchema::VERSION;
        expect($schema->isUpToDate())->toBeFalse();

        Wp::$networkOptions[WpdbSchema::VERSION_OPTION] = WpdbSchema::VERSION;
        expect($schema->isUpToDate())->toBeTrue();

        $schema->forget();
        expect($schema->isUpToDate())->toBeFalse();
    });

    it('forgets the version of a single site', function (): void {
        $schema = installedSchema(new wpdb);

        $schema->forget();

        expect($schema->isUpToDate())->toBeFalse();
    });

    it('describes the table with a binary key, a composite primary key and the two indexes', function (): void {
        $statement = (new WpdbSchema(new wpdb))->createTableStatement();

        expect($statement)->toContain('CREATE TABLE wp_portcullis_attempts')
            ->and($statement)->toContain('subject binary(32) NOT NULL')
            ->and($statement)->toContain('PRIMARY KEY  (scope,subject)')
            ->and($statement)->toContain('KEY expires (window_expires_at)')
            ->and($statement)->toContain('KEY locked (locked_until)')
            ->and($statement)->toContain('DEFAULT CHARACTER SET utf8mb4');
    });

    it('installs the table and records the version', function (): void {
        $wpdb = new wpdb;
        $wpdb->varResults = ['wp_portcullis_attempts'];
        $schema = new WpdbSchema($wpdb);

        $schema->install();

        expect(Wp::$dbDelta)->toHaveCount(1)
            ->and(Wp::$dbDelta[0])->toContain('CREATE TABLE wp_portcullis_attempts')
            ->and($schema->isUpToDate())->toBeTrue()
            ->and($schema->lastInstallError())->toBeNull();
    });

    it('records the version on the network on a multisite', function (): void {
        Wp::$multisite = true;
        $wpdb = new wpdb;
        $wpdb->varResults = ['wp_portcullis_attempts'];
        $schema = new WpdbSchema($wpdb);

        $schema->install();

        expect(Wp::$networkOptions[WpdbSchema::VERSION_OPTION])->toBe(WpdbSchema::VERSION)
            ->and(Wp::$options)->toBe([]);
    });

    it('clears a remembered failure once the table has been installed', function (): void {
        Wp::$transients[WpdbSchema::VERSION_OPTION.'_error'] = 'old failure';
        $wpdb = new wpdb;
        $wpdb->varResults = ['wp_portcullis_attempts'];
        $schema = new WpdbSchema($wpdb);

        $schema->install();

        expect($schema->lastInstallError())->toBeNull();
    });

    it('does not pretend the protection is active when the table cannot be created', function (): void {
        $log = tempnam(sys_get_temp_dir(), 'portcullis-log');
        $previous = ini_set('error_log', $log);
        $wpdb = new wpdb;
        $wpdb->last_error = 'CREATE command denied to user';
        $wpdb->varResults = [null];
        $schema = new WpdbSchema($wpdb);

        try {
            $schema->install();
            $thrown = null;
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $logged = (string) file_get_contents($log);
        ini_set('error_log', (string) $previous);
        unlink($log);

        expect($thrown)->toBeInstanceOf(RuntimeException::class)
            ->and($thrown->getMessage())->toContain('wp_portcullis_attempts could not be created: CREATE command denied to user')
            ->and($schema->isUpToDate())->toBeFalse()
            ->and($schema->lastInstallError())->toBe($thrown->getMessage())
            ->and($logged)->toContain('[portcullis] The table wp_portcullis_attempts could not be created');
    });

    it('suggests a cause when the database does not say why', function (): void {
        $wpdb = new wpdb;
        $wpdb->varResults = [null];
        $log = tempnam(sys_get_temp_dir(), 'portcullis-log');
        $previous = ini_set('error_log', $log);

        try {
            expect(fn () => (new WpdbSchema($wpdb))->install())->toThrow(RuntimeException::class, 'check that the database user may create tables.');
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($log);
        }
    });

    it('does nothing when the schema is already up to date', function (): void {
        $wpdb = new wpdb;
        installedSchema($wpdb)->ensure();

        expect(Wp::$dbDelta)->toBe([]);
    });

    it('does not retry an installation that just failed', function (): void {
        Wp::$transients[WpdbSchema::VERSION_OPTION.'_error'] = 'denied';

        expect(fn () => (new WpdbSchema(new wpdb))->ensure())->toThrow(RuntimeException::class, 'denied');
        expect(Wp::$dbDelta)->toBe([]);
    });

    it('installs on first use', function (): void {
        $wpdb = new wpdb;
        $wpdb->varResults = ['wp_portcullis_attempts'];

        (new WpdbSchema($wpdb))->ensure();

        expect(Wp::$dbDelta)->toHaveCount(1);
    });

    it('detects whether the table exists with an escaped LIKE', function (): void {
        $wpdb = new wpdb;
        $wpdb->base_prefix = 'wp_my_';
        $wpdb->varResults = ['wp_my_portcullis_attempts', null];
        $schema = new WpdbSchema($wpdb);

        expect($schema->exists())->toBeTrue()
            ->and($schema->exists())->toBeFalse()
            ->and($wpdb->queries[0])->toBe("SHOW TABLES LIKE 'wp\\\\_my\\\\_portcullis\\\\_attempts'");
    });

    it('keeps another table name\'s version under its own option', function (): void {
        $schema = new WpdbSchema(new wpdb, 'custom_attempts');
        Wp::$options['custom_attempts_schema_version'] = WpdbSchema::VERSION;

        expect($schema->table())->toBe('wp_custom_attempts')
            ->and($schema->isUpToDate())->toBeTrue()
            ->and($schema->lastInstallError())->toBeNull();

        Wp::$transients['custom_attempts_schema_version_error'] = 'x';

        expect($schema->lastInstallError())->toBe('x');
    });

    it('ignores an empty or non-string remembered error', function (): void {
        $schema = new WpdbSchema(new wpdb);

        Wp::$transients[WpdbSchema::VERSION_OPTION.'_error'] = '';
        expect($schema->lastInstallError())->toBeNull();

        Wp::$transients[WpdbSchema::VERSION_OPTION.'_error'] = ['x'];
        expect($schema->lastInstallError())->toBeNull();
    });
});

describe('WpdbAttemptStore', function (): void {
    beforeEach(function (): void {
        $this->wpdb = new wpdb;
        $this->schema = installedSchema($this->wpdb);
        $this->store = new WpdbAttemptStore($this->wpdb, $this->schema);
    });

    it('installs the table once, before the first query', function (): void {
        Wp::$options = [];
        $this->wpdb->varResults = ['wp_portcullis_attempts', null, null];

        $this->store->lockedUntil([subjectOf('a')], 1_000);
        $this->store->lockedUntil([subjectOf('a')], 1_000);

        expect(Wp::$dbDelta)->toHaveCount(1);
    });

    it('asks nothing for no subjects', function (): void {
        expect($this->store->lockedUntil([], 100))->toBeNull();

        $this->store->clear([]);

        expect($this->wpdb->queries)->toBe([]);
    });

    it('finds the longest running lockout among the subjects of an attempt', function (): void {
        $this->wpdb->varResults = ['5000', null];
        $ip = subjectOf('ip');
        $account = subjectOf('account', SubjectScope::Account);

        $until = $this->store->lockedUntil([$ip, $account], 1_000);
        $none = $this->store->lockedUntil([$ip], 1_000);

        expect($until)->toBe(5000)
            ->and($none)->toBeNull()
            ->and($this->wpdb->queries[0])->toContain('MAX(locked_until)')
            ->and($this->wpdb->queries[0])->toContain('(scope = '.SubjectScope::Ip->value.' AND subject = UNHEX(\''.$ip->hexKey().'\'))')
            ->and($this->wpdb->queries[0])->toContain(' OR (scope = '.SubjectScope::Account->value.' AND subject = UNHEX(\''.$account->hexKey().'\'))')
            ->and($this->wpdb->queries[0])->toEndWith('AND locked_until > 1000');
    });

    it('only ever sends the hashed key to the database, never the address or the login', function (): void {
        $this->wpdb->varResults = [null];

        $this->store->lockedUntil([subjectOf('203.0.113.9')], 1_000);

        expect($this->wpdb->queries[0])->not->toContain('203.0.113.9')
            ->and($this->wpdb->queries[0])->toMatch("/UNHEX\\('[0-9a-f]{64}'\\)/");
    });

    it('records a failure with one upsert and reads the counter back', function (): void {
        $this->wpdb->rowResults = [['failures' => '3', 'lockouts' => '1', 'locked_until' => '9000', 'window_expires_at' => '7000']];
        $subject = subjectOf('a');

        $state = $this->store->recordFailure($subject, 1_000, 7_000);

        expect($state->failures())->toBe(3)
            ->and($state->lockouts())->toBe(1)
            ->and($state->lockedUntil())->toBe(9000)
            ->and($state->windowExpiresAt())->toBe(7000)
            ->and($this->wpdb->queries[0])->toContain('INSERT INTO wp_portcullis_attempts')
            ->and($this->wpdb->queries[0])->toContain('ON DUPLICATE KEY UPDATE')
            ->and($this->wpdb->queries[0])->toContain('LEAST(failures + 1, 65535)')
            ->and($this->wpdb->queries[1])->toContain('SELECT failures, lockouts, locked_until, window_expires_at');
    });

    it('reads a counter that was never locked as unlocked', function (): void {
        $this->wpdb->rowResults = [['failures' => '1', 'lockouts' => '0', 'locked_until' => null, 'window_expires_at' => '7000']];

        expect($this->store->recordFailure(subjectOf('a'), 1_000, 7_000)->lockedUntil())->toBeNull();
    });

    it('reports a failure as forgotten when a concurrent clear deleted the row in between', function (): void {
        $this->wpdb->rowResults = [null];

        $state = $this->store->recordFailure(subjectOf('a'), 1_000, 7_000);

        expect($state->failures())->toBe(0)
            ->and($state->lockedUntil())->toBeNull()
            ->and($state->windowExpiresAt())->toBe(7_000);
    });

    it('locks only a row that reached the threshold, and says whether it did', function (): void {
        $this->wpdb->queryResults = [1, 0];
        $subject = subjectOf('a');

        $locked = $this->store->lock($subject, 9_000, 4, false);
        $notLocked = $this->store->lock($subject, 9_000, 4, true);

        expect($locked)->toBeTrue()
            ->and($notLocked)->toBeFalse()
            ->and($this->wpdb->queries[0])->toContain('AND failures >= 4')
            ->and($this->wpdb->queries[0])->toContain('IF(0 = 1, 0, LEAST(lockouts + 1, 255))')
            ->and($this->wpdb->queries[1])->toContain('IF(1 = 1, 0, LEAST(lockouts + 1, 255))');
    });

    it('deletes the rows of the subjects it is told to forget', function (): void {
        $this->store->clear([subjectOf('a'), subjectOf('b', SubjectScope::Account)]);

        expect($this->wpdb->queries[0])->toStartWith('DELETE FROM wp_portcullis_attempts WHERE (scope = ')
            ->and(substr_count($this->wpdb->queries[0], 'UNHEX('))->toBe(2);
    });

    it('purges expired counters in a bounded batch, sparing running lockouts', function (): void {
        $this->wpdb->queryResults = [250, false];

        expect($this->store->purgeExpired(2_000, 1_000))->toBe(250)
            ->and($this->store->purgeExpired(2_000, 0))->toBe(0)
            ->and($this->wpdb->queries[0])->toContain('window_expires_at < 2000 AND (locked_until IS NULL OR locked_until <= 2000)')
            ->and($this->wpdb->queries[0])->toEndWith('LIMIT 1000')
            ->and($this->wpdb->queries[1])->toEndWith('LIMIT 1');
    });

    it('lists the running lockouts, longest first, skipping rows of an unknown scope', function (): void {
        $a = hash('sha256', 'a');
        $b = hash('sha256', 'b');
        $this->wpdb->listResults = [[
            ['scope' => (string) SubjectScope::Ip->value, 'subject' => $a, 'locked_until' => '9000'],
            ['scope' => '99', 'subject' => $b, 'locked_until' => '8000'],
            ['scope' => (string) SubjectScope::Account->value, 'subject' => $b, 'locked_until' => '7000'],
        ]];

        $lockouts = $this->store->activeLockouts(1_000, 10);

        expect($lockouts)->toHaveCount(2)
            ->and($lockouts[0]->subject()->hexKey())->toBe($a)
            ->and($lockouts[0]->lockedUntil())->toBe(9000)
            ->and($lockouts[1]->subject()->scope())->toBe(SubjectScope::Account)
            ->and($this->wpdb->queries[0])->toContain('ORDER BY locked_until DESC')
            ->and($this->wpdb->queries[0])->toEndWith('LIMIT 10');
    });

    it('lists no lockout when the database returns no list', function (): void {
        $this->wpdb->listResults = [null];

        expect($this->store->activeLockouts(1_000, 0))->toBe([])
            ->and($this->wpdb->queries[0])->toEndWith('LIMIT 1');
    });

    it('turns a database error into an exception rather than a silent miss', function (string $operation): void {
        $this->wpdb->last_error = 'Lost connection to MySQL server';
        $store = $this->store;

        $run = match ($operation) {
            'lockedUntil' => fn () => $store->lockedUntil([subjectOf('a')], 1),
            'recordFailure' => fn () => $store->recordFailure(subjectOf('a'), 1, 2),
            'lock' => fn () => $store->lock(subjectOf('a'), 2, 1, false),
            'clear' => fn () => $store->clear([subjectOf('a')]),
            'purgeExpired' => fn () => $store->purgeExpired(1, 1),
            'activeLockouts' => fn () => $store->activeLockouts(1, 1),
        };

        expect($run)->toThrow(RuntimeException::class, '[portcullis] Lost connection to MySQL server');
    })->with(['lockedUntil', 'recordFailure', 'lock', 'clear', 'purgeExpired', 'activeLockouts']);

    it('forgets the schema version when the table has disappeared, so that the next request installs it again', function (): void {
        $this->wpdb->last_error = "Table 'db.wp_portcullis_attempts' doesn't exist";

        try {
            $this->store->lockedUntil([subjectOf('a')], 1);
        } catch (RuntimeException) {
        }

        expect($this->schema->isUpToDate())->toBeFalse();

        // ... and the store checks the schema again on its next call.
        $this->wpdb->last_error = '';
        $this->wpdb->varResults = ['wp_portcullis_attempts', null];
        $this->store->lockedUntil([subjectOf('a')], 1);

        expect(Wp::$dbDelta)->toHaveCount(1);
    });

    it('keeps the schema version on any other error', function (): void {
        $this->wpdb->last_error = 'Deadlock found when trying to get lock';

        try {
            $this->store->clear([subjectOf('a')]);
        } catch (RuntimeException) {
        }

        expect($this->schema->isUpToDate())->toBeTrue();
    });
});

describe('AttemptPurgeScheduler', function (): void {
    it('registers the purge on its event and the scheduling on init', function (): void {
        (new AttemptPurgeScheduler(new InMemoryAttemptStore, new FrozenClock, new WordPressHookRegistrar))->register();

        expect(Wp::callbacks(AttemptPurgeScheduler::EVENT))->toHaveCount(1)
            ->and(Wp::callbacks('init'))->toHaveCount(1);
    });

    it('schedules a daily purge an hour from now, only once', function (): void {
        $scheduler = new AttemptPurgeScheduler(new InMemoryAttemptStore, new FrozenClock(1_700_000_000), new WordPressHookRegistrar);

        $scheduler->schedule();
        $scheduler->schedule();

        expect(Wp::$scheduleCalls)->toBe([[1_700_003_600, 'daily', AttemptPurgeScheduler::EVENT]]);
    });

    it('purges expired counters and keeps live ones', function (): void {
        $clock = new FrozenClock(1_700_000_000);
        $store = new InMemoryAttemptStore;
        $store->recordFailure(subjectOf('old'), 1_699_990_000, 1_699_999_000);
        $store->recordFailure(subjectOf('live'), 1_699_999_990, 1_700_005_000);

        $deleted = (new AttemptPurgeScheduler($store, $clock, new WordPressHookRegistrar))->purge();

        expect($deleted)->toBe(1)
            ->and($store->count())->toBe(1);
    });

    it('works through several batches until one comes back short', function (): void {
        $store = new CountingPurgeStore(static fn (int $call, int $limit): int => $call < 3 ? $limit : 7);

        $deleted = (new AttemptPurgeScheduler($store, new FrozenClock, new WordPressHookRegistrar))->purge();

        expect($store->calls)->toBe(3)
            ->and($deleted)->toBe(2 * AttemptPurgeScheduler::BATCH_SIZE + 7);
    });

    it('never runs more than the maximum number of batches in one go', function (): void {
        $store = new CountingPurgeStore(static fn (int $call, int $limit): int => $limit);

        (new AttemptPurgeScheduler($store, new FrozenClock, new WordPressHookRegistrar))->purge();

        expect($store->calls)->toBe(AttemptPurgeScheduler::MAX_BATCHES);
    });

    it('logs a store failure instead of breaking the cron run', function (): void {
        $log = tempnam(sys_get_temp_dir(), 'portcullis-log');
        $previous = ini_set('error_log', $log);

        $deleted = (new AttemptPurgeScheduler(new ExplodingAttemptStore, new FrozenClock, new WordPressHookRegistrar))->purge();

        $logged = (string) file_get_contents($log);
        ini_set('error_log', (string) $previous);
        unlink($log);

        expect($deleted)->toBe(0)
            ->and($logged)->toContain('[portcullis] purge failed: [portcullis] connection refused');
    });
});

describe('StorageHealthNotice', function (): void {
    beforeEach(function (): void {
        $this->wpdb = new wpdb;
        $this->schema = new WpdbSchema($this->wpdb);
        $this->notice = new StorageHealthNotice($this->schema, new WordPressHookRegistrar);
    });

    it('installs on admin_init and warns on both admin screens', function (): void {
        $this->notice->register();

        expect(Wp::$hooks['admin_init'])->toHaveKey(1)
            ->and(Wp::callbacks('admin_notices'))->toHaveCount(1)
            ->and(Wp::callbacks('network_admin_notices'))->toHaveCount(1);
    });

    it('installs the table for an administrator', function (): void {
        Wp::$capabilities = ['manage_options'];
        $this->wpdb->varResults = ['wp_portcullis_attempts'];

        $this->notice->install();

        expect($this->schema->isUpToDate())->toBeTrue();
    });

    it('asks a network administrator on a multisite', function (): void {
        Wp::$multisite = true;
        Wp::$capabilities = ['manage_options'];

        $this->notice->install();

        expect(Wp::$dbDelta)->toBe([]);

        Wp::$capabilities = ['manage_network_options'];
        $this->wpdb->varResults = ['wp_portcullis_attempts'];
        $this->notice->install();

        expect(Wp::$dbDelta)->toHaveCount(1);
    });

    it('does not install for a visitor or a plain user', function (): void {
        Wp::$capabilities = ['read'];

        $this->notice->install();

        expect(Wp::$dbDelta)->toBe([]);
    });

    it('does nothing when the table is already installed', function (): void {
        Wp::$capabilities = ['manage_options'];
        Wp::$options[WpdbSchema::VERSION_OPTION] = WpdbSchema::VERSION;

        $this->notice->install();

        expect(Wp::$dbDelta)->toBe([]);
    });

    it('swallows an installation failure, which render() will report', function (): void {
        Wp::$capabilities = ['manage_options'];
        $this->wpdb->last_error = 'denied';
        $this->wpdb->varResults = [null];
        $log = tempnam(sys_get_temp_dir(), 'portcullis-log');
        $previous = ini_set('error_log', $log);

        $this->notice->install();

        ini_set('error_log', (string) $previous);
        unlink($log);
        Wp::$capabilities = ['manage_options'];

        ob_start();
        $this->notice->render();
        $html = (string) ob_get_clean();

        expect($html)->toContain('notice-error')
            ->and($html)->toContain('Brute-force protection is not active')
            ->and($html)->toContain('denied')
            ->and($html)->toContain('wp portcullis install')
            ->and($html)->toContain('15 minutes');
    });

    it('shows nothing when there is nothing to report', function (): void {
        Wp::$capabilities = ['manage_options'];

        ob_start();
        $this->notice->render();

        expect(ob_get_clean())->toBe('');
    });

    it('shows the failure to administrators only', function (): void {
        Wp::$transients[WpdbSchema::VERSION_OPTION.'_error'] = 'secret detail';
        Wp::$capabilities = ['edit_posts'];

        ob_start();
        $this->notice->render();

        expect(ob_get_clean())->toBe('');
    });

    it('escapes what the database said', function (): void {
        Wp::$transients[WpdbSchema::VERSION_OPTION.'_error'] = '<script>alert(1)</script>';
        Wp::$capabilities = ['manage_options'];

        ob_start();
        $this->notice->render();
        $html = (string) ob_get_clean();

        expect($html)->not->toContain('<script>')
            ->and($html)->toContain('&lt;script&gt;');
    });
});

describe('SlugCollisionNotice', function (): void {
    beforeEach(function (): void {
        $this->notice = new SlugCollisionNotice(LoginSlug::fromString('acces-secret'), new WordPressHookRegistrar);
    });

    it('registers on admin_notices', function (): void {
        $this->notice->register();

        expect(Wp::callbacks('admin_notices'))->toHaveCount(1);
    });

    it('warns an administrator that a page uses the slug, naming it', function (): void {
        Wp::$capabilities = ['manage_options'];
        Wp::$pages['acces-secret'] = (object) ['post_title' => 'My <b>page</b>'];

        ob_start();
        $this->notice->render();
        $html = (string) ob_get_clean();

        expect($html)->toContain('notice-warning')
            ->and($html)->toContain('My &lt;b&gt;page&lt;/b&gt;')
            ->and($html)->toContain('&quot;acces-secret&quot;')
            ->and(Wp::$pageLookups[0][2])->toBe(['post', 'page']);
    });

    it('says nothing when no content uses the slug', function (): void {
        Wp::$capabilities = ['manage_options'];

        ob_start();
        $this->notice->render();

        expect(ob_get_clean())->toBe('');
    });

    it('does not even look the slug up for someone who cannot manage options', function (): void {
        Wp::$pages['acces-secret'] = (object) ['post_title' => 'Page'];

        ob_start();
        $this->notice->render();

        expect(ob_get_clean())->toBe('')
            ->and(Wp::$pageLookups)->toBe([]);
    });
});
