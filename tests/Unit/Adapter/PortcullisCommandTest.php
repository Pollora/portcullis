<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\In\WordPress\AccountIdentifier;
use Pollora\Portcullis\Adapter\In\WordPress\AttemptPurgeScheduler;
use Pollora\Portcullis\Adapter\In\WordPress\Cli\PortcullisCommand;
use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Adapter\Out\WordPress\WpdbSchema;
use Pollora\Portcullis\Application\Service\DeriveSubjects;
use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;
use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\IpRange;
use Pollora\Portcullis\Domain\Model\ScopePolicy;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;
use Pollora\Portcullis\Domain\Model\ThrottleSettings;
use Pollora\Portcullis\Tests\Support\CliHalt;
use Pollora\Portcullis\Tests\Support\ExplodingAttemptStore;
use Pollora\Portcullis\Tests\Support\FixedEnumerationSettings;
use Pollora\Portcullis\Tests\Support\FrozenClock;
use Pollora\Portcullis\Tests\Support\InMemoryAttemptStore;
use Pollora\Portcullis\Tests\Support\InMemorySlugProvider;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

const CLI_SECRET = 'a-secret-long-enough-for-the-subject-derivation';

function enumerationSettings(bool $enabled = true, AuthorArchivePolicy $policy = AuthorArchivePolicy::PublicWithPosts): FixedEnumerationSettings
{
    return new FixedEnumerationSettings($enabled, $policy);
}

function cliSettings(bool $account = true, array $proxies = [], array $allowlist = [], bool $generic = true): ThrottleSettings
{
    return new ThrottleSettings(
        new ThrottlePolicy(86400, new ScopePolicy(4, 1200, 4, 86400), $account ? new ScopePolicy(20, 1200, 4, 86400) : null),
        IpRange::listFromString(implode(',', $proxies)),
        IpRange::listFromString(implode(',', $allowlist)),
        CLI_SECRET,
        $generic,
    );
}

/**
 * A command wired like the composition root does, on an in-memory store.
 *
 * @return array{command: PortcullisCommand, store: InMemoryAttemptStore, subjects: DeriveSubjects, clock: FrozenClock}
 */
function cliCommand(?string $slug = 'acces-secret', ?InMemoryAttemptStore $store = null, ?WpdbSchema $schema = null): array
{
    $store ??= new InMemoryAttemptStore;
    $clock = new FrozenClock;
    $subjects = new DeriveSubjects(CLI_SECRET);

    return [
        'command' => new PortcullisCommand(
            new InMemorySlugProvider($slug),
            cliSettings(),
            $store,
            $subjects,
            new AccountIdentifier,
            $clock,
            new AttemptPurgeScheduler($store, $clock, new WordPressHookRegistrar),
            $schema,
            enumerationSettings(),
        ),
        'store' => $store,
        'subjects' => $subjects,
        'clock' => $clock,
    ];
}

function lockOut(InMemoryAttemptStore $store, Subject $subject, int $until): void
{
    $store->recordFailure($subject, 1_700_000_000, 1_700_086_400);
    $store->lock($subject, $until, 1, false);
}

function printed(): string
{
    return implode("\n", WP_CLI::$lines);
}

describe('url', function (): void {
    it('prints the secret login URL', function (): void {
        cliCommand('acces-secret')['command']->url();

        expect(WP_CLI::$lines)->toBe(['https://example.test/acces-secret']);
    });

    it('stops with a message when no slug is configured', function (): void {
        expect(fn () => cliCommand(null)['command']->url())->toThrow(CliHalt::class, 'No login slug is configured');
        expect(fn () => cliCommand('  ')['command']->url())->toThrow(CliHalt::class, 'No login slug is configured');
    });

    it('stops with the reason when the configured slug is rejected', function (): void {
        expect(fn () => cliCommand('wp-admin')['command']->url())->toThrow(CliHalt::class);
    });
});

describe('status', function (): void {
    it('reports the three protections when everything is configured', function (): void {
        cliCommand('acces-secret')['command']->status();

        $out = printed();

        expect($out)->toContain('Hidden login: active.')
            ->and($out)->toContain('Login URL:    https://example.test/acces-secret')
            ->and($out)->toContain('wp-login.php: 404 for everyone.')
            ->and($out)->toContain('Brute-force protection: active.')
            ->and($out)->toContain('Per address:  4 failures lock out for')
            ->and($out)->toContain('Per account:  20 failures')
            ->and($out)->toContain('Trusted proxies: none (REMOTE_ADDR is used)')
            ->and($out)->toContain('Allowlist:    none')
            ->and($out)->toContain('Login errors: generic')
            ->and($out)->toContain('Account enumeration protection: active.')
            ->and($out)->toContain('/wp/v2/users:    removed for users who cannot list accounts.')
            ->and($out)->toContain('Author archives: public when they list posts');
    });

    it('says the hidden login is dormant when no slug is set', function (): void {
        cliCommand(null)['command']->status();

        expect(printed())->toContain('Hidden login: inactive (no slug configured).')
            ->and(printed())->toContain('wp-login.php and wp-admin/ behave as WordPress intends.');
    });

    it('says why the hidden login is dormant when the slug is rejected', function (): void {
        cliCommand('wp-admin')['command']->status();

        expect(printed())->toContain('Hidden login: inactive (the configured slug was rejected).')
            ->and(WP_CLI::$warnings)->toHaveCount(1);
    });

    it('lists proxies, allowlist and the storage state', function (): void {
        $wpdb = new wpdb;
        $schema = new WpdbSchema($wpdb);
        $command = new PortcullisCommand(
            new InMemorySlugProvider(null),
            cliSettings(account: false, proxies: ['10.0.0.1'], allowlist: ['203.0.113.0/24'], generic: false),
            new InMemoryAttemptStore,
            new DeriveSubjects(CLI_SECRET),
            new AccountIdentifier,
            new FrozenClock,
            null,
            $schema,
            enumerationSettings(false, AuthorArchivePolicy::Untouched),
        );
        Wp::$transients[WpdbSchema::VERSION_OPTION.'_error'] = 'CREATE denied';

        $command->status();

        $out = printed();

        expect($out)->toContain('Per account:  not counted.')
            ->and($out)->toContain('Trusted proxies: 10.0.0.1')
            ->and($out)->toContain('Allowlist:    203.0.113.0/24')
            ->and($out)->toContain('Login errors: WordPress defaults')
            ->and($out)->toContain('Storage:      table wp_portcullis_attempts (not installed yet)')
            ->and($out)->toContain('Account enumeration protection: inactive.')
            ->and($out)->toContain('Author archives: as WordPress serves them.')
            ->and($out)->not->toContain('/wp/v2/users:')
            ->and(WP_CLI::$warnings)->toBe(['Last installation attempt failed: CREATE denied']);
    });

    it('does not call an installed table "not installed yet"', function (): void {
        Wp::$options[WpdbSchema::VERSION_OPTION] = WpdbSchema::VERSION;
        $command = cliCommand(schema: new WpdbSchema(new wpdb))['command'];

        $command->status();

        expect(printed())->toContain('Storage:      table wp_portcullis_attempts')
            ->and(printed())->not->toContain('not installed yet')
            ->and(WP_CLI::$warnings)->toBe([]);
    });

    it('reports the brute-force protection as inactive without settings', function (): void {
        (new PortcullisCommand(new InMemorySlugProvider(null), enumerationSettings: enumerationSettings(true, AuthorArchivePolicy::Closed)))->status();

        expect(printed())->toContain('Brute-force protection: inactive.')
            ->and(printed())->toContain('Author archives: 404, author links point home.');
    });
});

describe('lockouts', function (): void {
    it('says so when nothing is locked', function (): void {
        cliCommand()['command']->lockouts([], []);

        expect(WP_CLI::$successes)->toBe(['No running lockout.'])
            ->and(WP_CLI::$tables)->toBe([]);
    });

    it('lists running lockouts, never the address or the login behind a key', function (): void {
        ['command' => $command, 'store' => $store, 'subjects' => $subjects, 'clock' => $clock] = cliCommand();
        $ip = $subjects->forIp(ClientIp::tryFromString('203.0.113.9'));
        lockOut($store, $ip, $clock->now() + 600);

        $command->lockouts([], ['format' => 'json']);

        $table = WP_CLI::$tables[0];

        expect($table['format'])->toBe('json')
            ->and($table['fields'])->toBe(['scope', 'key', 'locked_until', 'remaining'])
            ->and($table['items'])->toHaveCount(1)
            ->and($table['items'][0]['scope'])->toBe(SubjectScope::Ip->label())
            ->and($table['items'][0]['key'])->toBe($ip->hexKey())
            ->and($table['items'][0]['locked_until'])->toBe('2023-11-14 22:23:20 UTC')
            ->and(json_encode($table))->not->toContain('203.0.113.9');
    });

    it('is a table by default and honours the limit', function (): void {
        ['command' => $command, 'store' => $store, 'subjects' => $subjects, 'clock' => $clock] = cliCommand();
        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $address) {
            lockOut($store, $subjects->forIp(ClientIp::tryFromString($address)), $clock->now() + 600);
        }

        $command->lockouts([], ['limit' => '2']);

        expect(WP_CLI::$tables[0]['format'])->toBe('table')
            ->and(WP_CLI::$tables[0]['items'])->toHaveCount(2);
    });

    it('stops when the brute-force protection is off', function (): void {
        $command = new PortcullisCommand(new InMemorySlugProvider(null));

        expect(fn () => $command->lockouts([], []))->toThrow(CliHalt::class, 'Brute-force protection is disabled.');
    });

    it('stops with the store\'s message when it fails', function (): void {
        $command = new PortcullisCommand(new InMemorySlugProvider(null), cliSettings(), new ExplodingAttemptStore, new DeriveSubjects(CLI_SECRET), clock: new FrozenClock);

        expect(fn () => $command->lockouts([], []))->toThrow(CliHalt::class, '[portcullis] connection refused');
    });
});

describe('unlock', function (): void {
    it('forgets an address, and only that address', function (): void {
        ['command' => $command, 'store' => $store, 'subjects' => $subjects, 'clock' => $clock] = cliCommand();
        $target = $subjects->forIp(ClientIp::tryFromString('203.0.113.9'));
        $other = $subjects->forIp(ClientIp::tryFromString('203.0.113.10'));
        lockOut($store, $target, $clock->now() + 600);
        lockOut($store, $other, $clock->now() + 600);

        $command->unlock(['203.0.113.9']);

        expect($store->lockedUntil([$target], $clock->now()))->toBeNull()
            ->and($store->lockedUntil([$other], $clock->now()))->not->toBeNull()
            ->and(WP_CLI::$successes)->toBe(['Failures and lockouts of the address 203.0.113.9 forgotten.']);
    });

    it('forgets the whole /64 of an IPv6 address', function (): void {
        ['command' => $command, 'store' => $store, 'subjects' => $subjects, 'clock' => $clock] = cliCommand();
        $neighbour = $subjects->forIp(ClientIp::tryFromString('2001:db8:1:2::ffff'));
        lockOut($store, $neighbour, $clock->now() + 600);

        $command->unlock(['2001:db8:1:2::1']);

        expect($store->lockedUntil([$neighbour], $clock->now()))->toBeNull();
    });

    it('forgets an account by login', function (): void {
        ['command' => $command, 'store' => $store, 'subjects' => $subjects, 'clock' => $clock] = cliCommand();
        $account = $subjects->forAccount('login:ghost');
        lockOut($store, $account, $clock->now() + 600);

        $command->unlock(['ghost']);

        expect($store->lockedUntil([$account], $clock->now()))->toBeNull()
            ->and(WP_CLI::$successes[0])->toContain('account ghost');
    });

    it('forgets an account by the user it resolves to', function (): void {
        ['command' => $command, 'store' => $store, 'subjects' => $subjects, 'clock' => $clock] = cliCommand();
        Wp::$usersByLogin['jane'] = new WP_User(5, 'jane', 'jane');
        $account = $subjects->forAccount('user:5');
        lockOut($store, $account, $clock->now() + 600);

        $command->unlock(['jane']);

        expect($store->lockedUntil([$account], $clock->now()))->toBeNull();
    });

    it('forgets a key as shown by `lockouts`, in whichever scope it lives', function (): void {
        ['command' => $command, 'store' => $store, 'subjects' => $subjects, 'clock' => $clock] = cliCommand();
        $ip = $subjects->forIp(ClientIp::tryFromString('203.0.113.9'));
        $account = $subjects->forAccount('login:ghost');
        lockOut($store, $ip, $clock->now() + 600);
        lockOut($store, $account, $clock->now() + 600);

        $command->unlock([strtoupper($ip->hexKey())]);

        expect($store->lockedUntil([$ip], $clock->now()))->toBeNull()
            ->and($store->lockedUntil([$account], $clock->now()))->not->toBeNull()
            ->and(WP_CLI::$successes[0])->toContain('key '.$ip->hexKey());
    });

    it('asks for something to unlock', function (): void {
        $command = cliCommand()['command'];

        expect(fn () => $command->unlock([]))->toThrow(CliHalt::class, 'Give an IP address, a login or a key.');
        expect(fn () => $command->unlock(['   ']))->toThrow(CliHalt::class, 'Give an IP address, a login or a key.');
    });

    it('stops when the brute-force protection is off', function (): void {
        $command = new PortcullisCommand(new InMemorySlugProvider(null));

        expect(fn () => $command->unlock(['203.0.113.9']))->toThrow(CliHalt::class, 'Brute-force protection is disabled.');
    });

    it('stops with the store\'s message when it fails', function (): void {
        $command = new PortcullisCommand(new InMemorySlugProvider(null), cliSettings(), new ExplodingAttemptStore, new DeriveSubjects(CLI_SECRET), clock: new FrozenClock);

        expect(fn () => $command->unlock(['203.0.113.9']))->toThrow(CliHalt::class, 'connection refused');
    });
});

describe('install and purge', function (): void {
    it('has nothing to install without a database table', function (): void {
        expect(fn () => cliCommand()['command']->install())->toThrow(CliHalt::class, 'Nothing to install');
    });

    it('installs the table and says which', function (): void {
        $wpdb = new wpdb;
        $wpdb->varResults = ['wp_portcullis_attempts'];

        cliCommand(schema: new WpdbSchema($wpdb))['command']->install();

        expect(WP_CLI::$successes)->toBe(['Table wp_portcullis_attempts is up to date (schema version 1).']);
    });

    it('stops with the reason when the table cannot be created', function (): void {
        $wpdb = new wpdb;
        $wpdb->last_error = 'CREATE command denied';
        $wpdb->varResults = [null];
        $command = cliCommand(schema: new WpdbSchema($wpdb))['command'];
        $log = tempnam(sys_get_temp_dir(), 'portcullis-log');
        $previous = ini_set('error_log', $log);

        try {
            expect(fn () => $command->install())->toThrow(CliHalt::class, 'CREATE command denied');
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($log);
        }
    });

    it('purges and reports the count', function (): void {
        ['command' => $command, 'store' => $store] = cliCommand();
        $store->recordFailure(new Subject(SubjectScope::Ip, str_repeat('a', 32)), 1, 2);

        $command->purge();

        expect(WP_CLI::$successes)->toBe(['1 expired counter(s) deleted.']);
    });

    it('has nothing to purge when the protection is off', function (): void {
        $command = new PortcullisCommand(new InMemorySlugProvider(null));

        expect(fn () => $command->purge())->toThrow(CliHalt::class, 'Brute-force protection is disabled.');
    });
});

describe('registration', function (): void {
    it('registers nothing outside WP-CLI', function (): void {
        PortcullisCommand::register(cliCommand()['command']);

        expect(WP_CLI::$commands)->toBe([]);
    });
});
