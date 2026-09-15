<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\In\WordPress\Cli;

use Pollora\Portcullis\Adapter\In\WordPress\AccountIdentifier;
use Pollora\Portcullis\Adapter\In\WordPress\AttemptPurgeScheduler;
use Pollora\Portcullis\Adapter\Out\WordPress\WpdbSchema;
use Pollora\Portcullis\Application\Service\ClearAttempts;
use Pollora\Portcullis\Application\Service\DeriveSubjects;
use Pollora\Portcullis\Domain\Exception\InvalidLoginSlugException;
use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\LoginSlug;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Domain\Model\ThrottleSettings;
use Pollora\Portcullis\Port\Out\AttemptStorePort;
use Pollora\Portcullis\Port\Out\ClockPort;
use Pollora\Portcullis\Port\Out\SlugProviderPort;
use RuntimeException;
use WP_CLI;

/**
 * Operates Portcullis from a terminal.
 *
 * This is the recovery path. WP-CLI is never intercepted, so the command keeps
 * answering when the slug is wrong, when the constant was lost in a deployment,
 * or when an administrator has locked themselves out — situations where every
 * HTTP route into the site is refused by design.
 */
final class PortcullisCommand
{
    /**
     * @param  SlugProviderPort  $provider  Source of the raw login slug.
     * @param  ThrottleSettings|null  $settings  Brute-force protection settings, `null` when it is off.
     * @param  AttemptStorePort|null  $store  Where counters are kept, `null` when the protection is off.
     * @param  DeriveSubjects|null  $subjects  Derives storage keys, `null` when the protection is off.
     * @param  AccountIdentifier  $accounts  Resolves logins to stable identifiers.
     * @param  ClockPort|null  $clock  Current time, `null` when the protection is off.
     * @param  AttemptPurgeScheduler|null  $purge  Purges expired counters, `null` when the protection is off.
     * @param  WpdbSchema|null  $schema  Table schema, `null` unless the default MySQL store is used.
     */
    public function __construct(
        private readonly SlugProviderPort $provider,
        private readonly ?ThrottleSettings $settings = null,
        private readonly ?AttemptStorePort $store = null,
        private readonly ?DeriveSubjects $subjects = null,
        private readonly AccountIdentifier $accounts = new AccountIdentifier,
        private readonly ?ClockPort $clock = null,
        private readonly ?AttemptPurgeScheduler $purge = null,
        private readonly ?WpdbSchema $schema = null,
    ) {}

    /**
     * Registers `wp portcullis`, and its 1.x alias `wp hidden-login`, when running under WP-CLI.
     *
     * @param  self  $command  The command, wired by the composition root.
     */
    public static function register(self $command): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        WP_CLI::add_command('portcullis', $command);

        // 1.x name, kept so that runbooks written for pollora/hidden-login still work.
        WP_CLI::add_command('hidden-login', $command);
    }

    /**
     * Prints the URL the login screen is served from.
     *
     * ## EXAMPLES
     *
     *     wp portcullis url
     *
     * @subcommand url
     */
    public function url(): void
    {
        try {
            $slug = $this->slug();
        } catch (InvalidLoginSlugException $exception) {
            WP_CLI::error($exception->getMessage());

            return;
        }

        if ($slug === null) {
            WP_CLI::error('No login slug is configured: WordPress serves wp-login.php as usual.');

            return;
        }

        WP_CLI::line(home_url($slug->toPath()));
    }

    /**
     * Prints what is currently enforced.
     *
     * ## EXAMPLES
     *
     *     wp portcullis status
     *
     * @subcommand status
     */
    public function status(): void
    {
        $this->hiddenLoginStatus();
        WP_CLI::line('');
        $this->throttleStatus();
    }

    /**
     * Lists running lockouts.
     *
     * Subjects are shown by their key only: addresses and logins are never
     * stored in clear. The lockout log lines carry them.
     *
     * ## OPTIONS
     *
     * [--limit=<limit>]
     * : Maximum number of lockouts to list.
     * ---
     * default: 50
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp portcullis lockouts
     *
     * @subcommand lockouts
     *
     * @param  list<string>  $args  Positional arguments.
     * @param  array<string, string>  $assocArgs  Named arguments.
     */
    public function lockouts(array $args, array $assocArgs): void
    {
        [$store, , $clock] = $this->throttle();

        $now = $clock->now();
        $rows = [];

        foreach ($this->attempt(fn () => $store->activeLockouts($now, max(1, (int) ($assocArgs['limit'] ?? 50)))) as $lockout) {
            $rows[] = [
                'scope' => $lockout->subject()->scope()->label(),
                'key' => $lockout->subject()->hexKey(),
                'locked_until' => gmdate('Y-m-d H:i:s', $lockout->lockedUntil()).' UTC',
                'remaining' => human_time_diff($now, $lockout->lockedUntil()),
            ];
        }

        if ($rows === []) {
            WP_CLI::success('No running lockout.');

            return;
        }

        WP_CLI\Utils\format_items($assocArgs['format'] ?? 'table', $rows, ['scope', 'key', 'locked_until', 'remaining']);
    }

    /**
     * Lifts the lockout of an address, an account or a key, and forgets its failures.
     *
     * ## OPTIONS
     *
     * <subject>
     * : An IP address, a username or email address, or a key as printed by `wp portcullis lockouts`.
     *
     * ## EXAMPLES
     *
     *     wp portcullis unlock 203.0.113.7
     *     wp portcullis unlock admin
     *     wp portcullis unlock 3f2a…e91c
     *
     * @subcommand unlock
     *
     * @param  list<string>  $args  Positional arguments.
     */
    public function unlock(array $args): void
    {
        [$store, $subjects] = $this->throttle();

        $value = trim($args[0] ?? '');

        if ($value === '') {
            WP_CLI::error('Give an IP address, a login or a key.');

            return;
        }

        $ip = ClientIp::tryFromString($value);

        if ($ip !== null) {
            $targets = [$subjects->forIp($ip)];
            $label = 'address '.$ip->bucket();
        } elseif (preg_match('/^[0-9a-f]{64}$/i', $value) === 1) {
            $targets = [
                Subject::fromHex(SubjectScope::Ip, $value),
                Subject::fromHex(SubjectScope::Account, $value),
            ];
            $label = 'key '.strtolower($value);
        } else {
            $targets = [$subjects->forAccount($this->accounts->forLogin($value))];
            $label = 'account '.$value;
        }

        $this->attempt(fn () => (new ClearAttempts($store))->clear($targets));

        WP_CLI::success(sprintf('Failures and lockouts of the %s forgotten.', $label));
    }

    /**
     * Creates or upgrades the table failure counters live in.
     *
     * Runs on its own the first time a login is attempted; call it from a
     * deployment script to take that cost, and any permission error, out of
     * the request path.
     *
     * ## EXAMPLES
     *
     *     wp portcullis install
     *
     * @subcommand install
     */
    public function install(): void
    {
        if ($this->schema === null) {
            WP_CLI::error('Nothing to install: the attempts are not stored in the WordPress database.');

            return;
        }

        $this->attempt(fn () => $this->schema->install());

        WP_CLI::success(sprintf('Table %s is up to date (schema version %d).', $this->schema->table(), WpdbSchema::VERSION));
    }

    /**
     * Deletes expired failure counters now, instead of waiting for the daily cron event.
     *
     * ## EXAMPLES
     *
     *     wp portcullis purge
     *
     * @subcommand purge
     */
    public function purge(): void
    {
        if ($this->purge === null) {
            WP_CLI::error('Brute-force protection is disabled.');

            return;
        }

        WP_CLI::success(sprintf('%d expired counter(s) deleted.', $this->purge->purge()));
    }

    /**
     * Prints the state of the hidden login screen.
     */
    private function hiddenLoginStatus(): void
    {
        try {
            $slug = $this->slug();
        } catch (InvalidLoginSlugException $exception) {
            WP_CLI::line('Hidden login: inactive (the configured slug was rejected).');
            WP_CLI::warning($exception->getMessage());

            return;
        }

        if ($slug === null) {
            WP_CLI::line('Hidden login: inactive (no slug configured).');
            WP_CLI::line('wp-login.php and wp-admin/ behave as WordPress intends.');

            return;
        }

        WP_CLI::line('Hidden login: active.');
        WP_CLI::line('Login URL:    '.home_url($slug->toPath()));
        WP_CLI::line('wp-login.php: 404 for everyone.');
        WP_CLI::line('wp-admin/:    404 for anonymous visitors, untouched once authenticated.');
    }

    /**
     * Prints the state of the brute-force protection.
     */
    private function throttleStatus(): void
    {
        if ($this->settings === null) {
            WP_CLI::line('Brute-force protection: inactive.');

            return;
        }

        $policy = $this->settings->policy();
        $ip = $policy->forScope(SubjectScope::Ip);
        $account = $policy->forScope(SubjectScope::Account);

        WP_CLI::line('Brute-force protection: active.');

        if ($ip !== null) {
            WP_CLI::line(sprintf(
                'Per address:  %d failures lock out for %s; lockout #%d lasts %s.',
                $ip->maxRetries(),
                human_time_diff(0, $ip->lockoutDuration()),
                $ip->maxLockouts(),
                human_time_diff(0, $ip->longLockoutDuration()),
            ));
        }

        WP_CLI::line($account === null
            ? 'Per account:  not counted.'
            : sprintf('Per account:  %d failures, all addresses combined, lock out for %s.', $account->maxRetries(), human_time_diff(0, $account->lockoutDuration())));

        WP_CLI::line('Counters kept: '.human_time_diff(0, $policy->retriesValidity()).' after the last failure.');
        WP_CLI::line('Trusted proxies: '.($this->settings->trustedProxies() === [] ? 'none (REMOTE_ADDR is used)' : implode(', ', array_map('strval', $this->settings->trustedProxies()))));
        WP_CLI::line('Allowlist:    '.($this->settings->allowlist() === [] ? 'none' : implode(', ', array_map('strval', $this->settings->allowlist()))));
        WP_CLI::line('Login errors: '.($this->settings->genericErrors() ? 'generic' : 'WordPress defaults'));

        if ($this->schema !== null) {
            WP_CLI::line('Storage:      table '.$this->schema->table().($this->schema->isUpToDate() ? '' : ' (not installed yet)'));
        }
    }

    /**
     * The throttle services, or an error when the protection is off.
     *
     * @return array{0: AttemptStorePort, 1: DeriveSubjects, 2: ClockPort}
     */
    private function throttle(): array
    {
        if ($this->store === null || $this->subjects === null || $this->clock === null) {
            WP_CLI::error('Brute-force protection is disabled.');

            exit(1);
        }

        return [$this->store, $this->subjects, $this->clock];
    }

    /**
     * Runs a storage operation, turning its failure into a CLI error.
     *
     * @template T
     *
     * @param  callable(): T  $operation  Operation to run.
     * @return T
     */
    private function attempt(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RuntimeException $exception) {
            WP_CLI::error($exception->getMessage());

            exit(1);
        }
    }

    /**
     * Resolves the configured slug.
     *
     * @return LoginSlug|null `null` when the feature is not configured at all.
     *
     * @throws InvalidLoginSlugException When a value is configured but unusable.
     */
    private function slug(): ?LoginSlug
    {
        $raw = $this->provider->slug();

        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return LoginSlug::fromString($raw);
    }
}
