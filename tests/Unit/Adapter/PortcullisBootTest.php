<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\WpdbSchema;
use Pollora\Portcullis\Domain\Exception\InvalidThrottlePolicyException;
use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;
use Pollora\Portcullis\Domain\Model\FeatureState;
use Pollora\Portcullis\Domain\Model\ScopePolicy;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;
use Pollora\Portcullis\Domain\Model\ThrottleSettings;
use Pollora\Portcullis\Port\Out\AccountEnumerationSettingsPort;
use Pollora\Portcullis\Port\Out\AttemptStorePort;
use Pollora\Portcullis\Port\Out\FeatureTogglePort;
use Pollora\Portcullis\Port\Out\SlugProviderPort;
use Pollora\Portcullis\Port\Out\ThrottleSettingsPort;
use Pollora\Portcullis\Portcullis;
use Pollora\Portcullis\Tests\Support\FixedEnumerationSettings;
use Pollora\Portcullis\Tests\Support\InMemoryAttemptStore;
use Pollora\Portcullis\Tests\Support\InMemorySlugProvider;
use Pollora\Portcullis\Tests\Support\RecordingHooks;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

/**
 * Boots the package against the fake plugin API.
 *
 * Only what a test names is injected; the rest is switched off, so that each
 * test shows which part of the configuration brings which hooks.
 *
 * @param  array{slugProvider?: SlugProviderPort, toggle?: FeatureTogglePort, throttleSettings?: ThrottleSettingsPort, store?: AttemptStorePort|null, enumerationSettings?: AccountEnumerationSettingsPort}  $overrides
 */
function bootWith(array $overrides = []): RecordingHooks
{
    $hooks = new RecordingHooks;

    // PHPUnit 12 redirects error_log() while a test runs, after beforeEach: point it at the test's own log again.
    $log = $GLOBALS['portcullis_test_log'] ?? null;
    if (is_string($log)) {
        ini_set('error_log', $log);
    }

    Portcullis::boot(...array_merge([
        'slugProvider' => new InMemorySlugProvider(null),
        'toggle' => bootToggle(true),
        'hooks' => $hooks,
        'throttleSettings' => bootThrottle(false),
        'store' => new InMemoryAttemptStore,
        'enumerationSettings' => new FixedEnumerationSettings(false, AuthorArchivePolicy::Untouched),
    ], $overrides));

    return $hooks;
}

function bootToggle(bool $enabled): FeatureTogglePort
{
    return new class($enabled) implements FeatureTogglePort
    {
        public function __construct(private readonly bool $enabled) {}

        public function state(): FeatureState
        {
            return $this->enabled ? FeatureState::Enabled : FeatureState::Disabled;
        }
    };
}

function bootThrottle(bool $enabled, ?Closure $settings = null): ThrottleSettingsPort
{
    return new class($enabled, $settings) implements ThrottleSettingsPort
    {
        public function __construct(private readonly bool $enabled, private readonly ?Closure $settings) {}

        public function isEnabled(): bool
        {
            return $this->enabled;
        }

        public function settings(): ThrottleSettings
        {
            return ($this->settings ?? static fn (): ThrottleSettings => new ThrottleSettings(
                new ThrottlePolicy(3600, new ScopePolicy(3, 600, 2, 7200), null),
                [],
                [],
                'a-secret-long-enough-for-the-subject-derivation',
                true,
            ))();
        }
    };
}

function resetBoot(): void
{
    (new ReflectionProperty(Portcullis::class, 'booted'))->setValue(null, false);
}

function hasHooks(string ...$hooks): bool
{
    foreach ($hooks as $hook) {
        if (Wp::callbacks($hook) === []) {
            return false;
        }
    }

    return true;
}

beforeEach(function (): void {
    resetBoot();
    $this->log = tempnam(sys_get_temp_dir(), 'portcullis-log');
    $this->previousLog = ini_set('error_log', $this->log);
    $GLOBALS['portcullis_test_log'] = $this->log;
});

afterEach(function (): void {
    resetBoot();
    ini_set('error_log', (string) $this->previousLog);
    unset($GLOBALS['portcullis_test_log']);
    @unlink($this->log);
});

it('registers nothing when the kill switch is off, and can still boot later', function (): void {
    bootWith(['toggle' => bootToggle(false), 'slugProvider' => new InMemorySlugProvider('acces-secret'), 'throttleSettings' => bootThrottle(true)]);

    expect(Wp::$hooks)->toBe([]);

    bootWith(['slugProvider' => new InMemorySlugProvider('acces-secret')]);

    expect(hasHooks('plugins_loaded'))->toBeTrue();
});

it('registers its hooks only once', function (): void {
    bootWith(['slugProvider' => new InMemorySlugProvider('acces-secret')]);
    $count = count(Wp::callbacks('site_url'));

    bootWith(['slugProvider' => new InMemorySlugProvider('acces-secret')]);

    expect(count(Wp::callbacks('site_url')))->toBe($count)->and($count)->toBe(1);
});

it('leaves the login alone, fail-open, when no slug is configured', function (): void {
    bootWith();

    expect(Wp::$hooks)->toBe([]);
});

it('serves the login from the slug and hides the defaults when one is configured', function (): void {
    bootWith(['slugProvider' => new InMemorySlugProvider('acces-secret')]);

    expect(hasHooks('site_url', 'network_site_url', 'wp_redirect', 'plugins_loaded', 'template_redirect', 'admin_notices'))->toBeTrue();
});

it('keeps WordPress\' stock login and reports it when the slug is rejected', function (): void {
    bootWith(['slugProvider' => new InMemorySlugProvider('wp-admin')]);

    expect(hasHooks('plugins_loaded', 'site_url'))->toBeFalse()
        ->and((string) file_get_contents($this->log))->toContain('[portcullis] The login slug "wp-admin" is reserved')
        ->and(Wp::$hooks['admin_notices'][10])->toHaveCount(1)
        ->and(Wp::$hooks['admin_notices'][10][0][1])->toBe(0);

    Wp::$capabilities = ['manage_options'];
    ob_start();
    do_action('admin_notices');
    $html = (string) ob_get_clean();

    expect($html)->toContain('notice notice-error')->and($html)->toContain('Portcullis');
});

it('shows the configuration error to administrators only', function (): void {
    bootWith(['slugProvider' => new InMemorySlugProvider('wp-admin')]);

    ob_start();
    do_action('admin_notices');

    expect(ob_get_clean())->toBe('');
});

it('hides account slugs from visitors unless switched off', function (): void {
    bootWith(['enumerationSettings' => new FixedEnumerationSettings(true, AuthorArchivePolicy::Untouched)]);

    expect(hasHooks('rest_endpoints', 'wp_sitemaps_add_provider', 'oembed_response_data', 'comment_class'))->toBeTrue()
        ->and(hasHooks('pre_handle_404'))->toBeFalse();
});

it('does not hide account slugs when the protection is off', function (): void {
    bootWith();

    expect(hasHooks('rest_endpoints'))->toBeFalse();
});

it('guards the author archives on their own, without hiding the REST routes', function (): void {
    bootWith(['enumerationSettings' => new FixedEnumerationSettings(false, AuthorArchivePolicy::PublicWithPosts)]);

    expect(hasHooks('pre_handle_404'))->toBeTrue()
        ->and(hasHooks('rest_endpoints'))->toBeFalse()
        ->and(hasHooks('author_link'))->toBeFalse();
});

it('rewrites author links as well when the archives are closed', function (): void {
    bootWith(['enumerationSettings' => new FixedEnumerationSettings(false, AuthorArchivePolicy::Closed)]);

    expect(hasHooks('pre_handle_404', 'author_link'))->toBeTrue();
});

it('wires the brute-force protection on every login path, with the injected store', function (): void {
    bootWith(['throttleSettings' => bootThrottle(true)]);

    expect(hasHooks('authenticate', 'wp_login_failed', 'wp_login', 'determine_current_user', 'application_password_failed_authentication', 'shake_error_codes'))->toBeTrue()
        ->and(hasHooks('portcullis_purge_attempts', 'init'))->toBeTrue()
        ->and(hasHooks('wp_login_errors'))->toBeTrue()
        // An injected store has no schema to look after.
        ->and(hasHooks('admin_init'))->toBeFalse();
});

it('does not wire the brute-force protection when it is off', function (): void {
    bootWith();

    expect(hasHooks('authenticate', 'init'))->toBeFalse();
});

it('keeps WordPress\' own login errors when generic errors are switched off', function (): void {
    $settings = bootThrottle(true, static fn (): ThrottleSettings => new ThrottleSettings(
        new ThrottlePolicy(3600, new ScopePolicy(3, 600, 2, 7200), null), [], [], 'a-secret-long-enough-for-the-subject-derivation', false,
    ));

    bootWith(['throttleSettings' => $settings]);

    expect(hasHooks('authenticate'))->toBeTrue()
        ->and(hasHooks('wp_login_errors'))->toBeFalse();
});

it('applies the default thresholds, not no protection, when the configuration is rejected', function (): void {
    $settings = bootThrottle(true, static fn (): ThrottleSettings => throw InvalidThrottlePolicyException::notAnInteger('PORTCULLIS_MAX_RETRIES', 'many'));

    bootWith(['throttleSettings' => $settings]);

    expect(hasHooks('authenticate', 'wp_login_failed'))->toBeTrue()
        ->and((string) file_get_contents($this->log))->toContain('PORTCULLIS_MAX_RETRIES must be an integer')
        ->and(Wp::callbacks('admin_notices'))->toHaveCount(1);
});

it('applies the default thresholds when the secret is too short to derive subjects from', function (): void {
    $settings = bootThrottle(true, static fn (): ThrottleSettings => new ThrottleSettings(
        new ThrottlePolicy(3600, new ScopePolicy(3, 600, 2, 7200), null), [], [], 'short', true,
    ));

    bootWith(['throttleSettings' => $settings]);

    expect(hasHooks('authenticate'))->toBeTrue()
        ->and((string) file_get_contents($this->log))->toContain('[portcullis]');
});

it('skips the brute-force protection, rather than failing, when there is no database to store counters in', function (): void {
    bootWith(['throttleSettings' => bootThrottle(true), 'store' => null]);

    expect(hasHooks('authenticate'))->toBeFalse();
});

it('stores the counters in the WordPress database by default, and looks after the table', function (): void {
    $wpdb = new wpdb;
    $GLOBALS['wpdb'] = $wpdb;

    bootWith(['throttleSettings' => bootThrottle(true), 'store' => null]);

    expect(hasHooks('authenticate', 'admin_init', 'network_admin_notices'))->toBeTrue()
        ->and(Wp::$hooks['admin_init'])->toHaveKey(1)
        ->and((new WpdbSchema($wpdb))->isUpToDate())->toBeFalse();
});

it('boots on the default adapters, reading its configuration from the environment', function (): void {
    // Nothing is configured in the test process: no slug (the login stays
    // stock), throttle and enumeration protections on, WordPress hooks.
    Portcullis::boot(store: new InMemoryAttemptStore);

    expect(hasHooks('authenticate', 'rest_endpoints', 'pre_handle_404'))->toBeTrue()
        ->and(hasHooks('plugins_loaded'))->toBeFalse();
});

it('keeps the generic login errors on when it falls back to the default thresholds', function (): void {
    $settings = bootThrottle(true, static fn (): ThrottleSettings => throw InvalidThrottlePolicyException::notAnInteger('PORTCULLIS_MAX_RETRIES', 'many'));

    bootWith(['throttleSettings' => $settings]);

    expect(hasHooks('wp_login_errors'))->toBeTrue();
});

it('does not trust a $wpdb that is not one', function (): void {
    $GLOBALS['wpdb'] = new stdClass;

    bootWith(['throttleSettings' => bootThrottle(true), 'store' => null]);

    expect(hasHooks('authenticate'))->toBeFalse();
});

it('registers through the hook system it is given', function (): void {
    $hooks = bootWith(['slugProvider' => new InMemorySlugProvider('acces-secret')]);

    expect($hooks->removedCallback('template_redirect', 'wp_redirect_admin_locations'))->toBeTrue();
});
