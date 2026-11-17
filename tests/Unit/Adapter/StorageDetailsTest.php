<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\WpdbAttemptStore;
use Pollora\Portcullis\Adapter\Out\WordPress\WpdbSchema;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

function detailSubject(string $seed, SubjectScope $scope = SubjectScope::Ip): Subject
{
    return new Subject($scope, hash('sha256', $seed, true));
}

/**
 * A database that reports an error after a given kind of call only, and not after the others.
 */
function stagedErrorWpdb(string $failAfter): wpdb
{
    return new class($failAfter) extends wpdb
    {
        public function __construct(private readonly string $failAfter) {}

        public function query(string $query): int|bool
        {
            $this->last_error = $this->failAfter === 'query' ? 'Lost connection to MySQL server' : '';

            return parent::query($query);
        }

        public function get_row(string $query, string $output = OBJECT): mixed
        {
            $this->last_error = $this->failAfter === 'get_row' ? 'Lost connection to MySQL server' : '';

            return parent::get_row($query, $output);
        }
    };
}

describe('WpdbSchema details', function (): void {
    it('describes the table exactly, one column per line, as dbDelta expects', function (): void {
        $wpdb = new wpdb;
        $wpdb->base_prefix = 'bedrock_';

        expect((new WpdbSchema($wpdb))->createTableStatement())->toBe(
            "CREATE TABLE bedrock_portcullis_attempts (\n"
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
            .') DEFAULT CHARACTER SET utf8mb4;'
        );
    });

    it('records the version of a single site so that it is loaded with every request', function (): void {
        $wpdb = new wpdb;
        $wpdb->varResults = ['wp_portcullis_attempts'];

        (new WpdbSchema($wpdb))->install();

        expect(Wp::$autoload[WpdbSchema::VERSION_OPTION])->toBeTrue();
    });
});

describe('WpdbAttemptStore details', function (): void {
    it('installs the table before the first call of every operation', function (string $operation): void {
        $wpdb = new wpdb;
        $wpdb->varResults = ['wp_portcullis_attempts'];
        $store = new WpdbAttemptStore($wpdb, new WpdbSchema($wpdb));
        $subject = detailSubject('a');

        match ($operation) {
            'lockedUntil' => $store->lockedUntil([$subject], 1),
            'recordFailure' => $store->recordFailure($subject, 1, 2),
            'lock' => $store->lock($subject, 2, 1, false),
            'clear' => $store->clear([$subject]),
            'purgeExpired' => $store->purgeExpired(1, 1),
            'activeLockouts' => $store->activeLockouts(1, 1),
        };

        expect(Wp::$dbDelta)->toHaveCount(1);
    })->with(['lockedUntil', 'recordFailure', 'lock', 'clear', 'purgeExpired', 'activeLockouts']);

    it('installs once for a clear of nothing too', function (): void {
        $wpdb = new wpdb;
        $wpdb->varResults = ['wp_portcullis_attempts'];

        (new WpdbAttemptStore($wpdb, new WpdbSchema($wpdb)))->clear([]);

        expect(Wp::$dbDelta)->toHaveCount(1);
    });

    it('does not look at the schema again once it has made sure of it', function (): void {
        $wpdb = new wpdb;
        $wpdb->varResults = ['wp_portcullis_attempts'];
        $store = new WpdbAttemptStore($wpdb, new WpdbSchema($wpdb));

        $store->lockedUntil([detailSubject('a')], 1);
        Wp::$options = [];
        $store->lockedUntil([detailSubject('a')], 1);

        expect(Wp::$dbDelta)->toHaveCount(1);
    });

    it('reports the failed upsert even when reading the counter back works', function (): void {
        $wpdb = stagedErrorWpdb('query');
        $store = new WpdbAttemptStore($wpdb, new WpdbSchema($wpdb));
        Wp::$options[WpdbSchema::VERSION_OPTION] = WpdbSchema::VERSION;

        expect(fn () => $store->recordFailure(detailSubject('a'), 1, 2))
            ->toThrow(RuntimeException::class, '[portcullis] Lost connection to MySQL server');
    });

    it('reports the failed read of the counter', function (): void {
        $wpdb = stagedErrorWpdb('get_row');
        $store = new WpdbAttemptStore($wpdb, new WpdbSchema($wpdb));
        Wp::$options[WpdbSchema::VERSION_OPTION] = WpdbSchema::VERSION;

        expect(fn () => $store->recordFailure(detailSubject('a'), 1, 2))
            ->toThrow(RuntimeException::class, '[portcullis] Lost connection to MySQL server');
    });

    it('reports a forgotten counter with no lockout at all', function (): void {
        $wpdb = new wpdb;
        Wp::$options[WpdbSchema::VERSION_OPTION] = WpdbSchema::VERSION;
        $wpdb->rowResults = [null];

        $state = (new WpdbAttemptStore($wpdb, new WpdbSchema($wpdb)))->recordFailure(detailSubject('a'), 1, 7);

        expect($state->failures())->toBe(0)
            ->and($state->lockouts())->toBe(0);
    });

    it('lists lockouts as regular ones, whatever their length', function (): void {
        $wpdb = new wpdb;
        Wp::$options[WpdbSchema::VERSION_OPTION] = WpdbSchema::VERSION;
        $wpdb->listResults = [[['scope' => (string) SubjectScope::Ip->value, 'subject' => hash('sha256', 'a'), 'locked_until' => '9000']]];

        $lockouts = (new WpdbAttemptStore($wpdb, new WpdbSchema($wpdb)))->activeLockouts(1_000, 10);

        expect($lockouts)->toHaveCount(1)
            ->and($lockouts[0]->isLong())->toBeFalse();
    });
});

describe('notices', function (): void {
    it('tells an administrator how long the next automatic attempt is away', function (): void {
        Wp::$transients[WpdbSchema::VERSION_OPTION.'_error'] = 'no CREATE privilege';
        Wp::$capabilities = ['manage_options'];

        ob_start();
        (new Pollora\Portcullis\Adapter\In\WordPress\StorageHealthNotice(new WpdbSchema(new wpdb), new Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar))->render();
        $html = (string) ob_get_clean();

        expect($html)->toContain('wait 15 minutes for the next automatic attempt');
    });

    it('looks for a collision among the public post types only', function (): void {
        Wp::$capabilities = ['manage_options'];
        Wp::$publicPostTypes = ['post', 'page', 'product'];

        ob_start();
        (new Pollora\Portcullis\Adapter\In\WordPress\SlugCollisionNotice(Pollora\Portcullis\Domain\Model\LoginSlug::fromString('acces-secret'), new Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar))->render();
        ob_end_clean();

        expect(Wp::$pageLookups[0][2])->toBe(['post', 'page', 'product']);
    });
});
