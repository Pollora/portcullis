<?php

declare(strict_types=1);

namespace Pollora\Portcullis;

use InvalidArgumentException;
use Pollora\Portcullis\Adapter\In\WordPress\AccountIdentifier;
use Pollora\Portcullis\Adapter\In\WordPress\AttemptPurgeScheduler;
use Pollora\Portcullis\Adapter\In\WordPress\Cli\PortcullisCommand;
use Pollora\Portcullis\Adapter\In\WordPress\GenericLoginErrors;
use Pollora\Portcullis\Adapter\In\WordPress\HiddenLoginRouter;
use Pollora\Portcullis\Adapter\In\WordPress\LoginThrottleGuard;
use Pollora\Portcullis\Adapter\In\WordPress\LoginUrlRewriter;
use Pollora\Portcullis\Adapter\In\WordPress\SlugCollisionNotice;
use Pollora\Portcullis\Adapter\In\WordPress\StockAliasRouter;
use Pollora\Portcullis\Adapter\Out\Pollora\PolloraHookRegistrar;
use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentFeatureToggle;
use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentSlugProvider;
use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentThrottleSettings;
use Pollora\Portcullis\Adapter\Out\WordPress\ErrorLogLockoutNotifier;
use Pollora\Portcullis\Adapter\Out\WordPress\SuperglobalClientAddress;
use Pollora\Portcullis\Adapter\Out\WordPress\SuperglobalRequestContext;
use Pollora\Portcullis\Adapter\Out\WordPress\SystemClock;
use Pollora\Portcullis\Adapter\Out\WordPress\ThemeNotFoundResponder;
use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Adapter\Out\WordPress\WpdbAttemptStore;
use Pollora\Portcullis\Adapter\Out\WordPress\WpdbSchema;
use Pollora\Portcullis\Adapter\Out\WordPress\WpLoginScreenRenderer;
use Pollora\Portcullis\Application\Service\CheckLockout;
use Pollora\Portcullis\Application\Service\ClassifyStockAlias;
use Pollora\Portcullis\Application\Service\ClearAttempts;
use Pollora\Portcullis\Application\Service\DeriveSubjects;
use Pollora\Portcullis\Application\Service\GuardDefaultEndpoints;
use Pollora\Portcullis\Application\Service\MatchHiddenLoginRequest;
use Pollora\Portcullis\Application\Service\RecordFailedAttempt;
use Pollora\Portcullis\Application\Service\ResolveClientIp;
use Pollora\Portcullis\Application\Service\ResolveLoginSlug;
use Pollora\Portcullis\Application\Service\RewriteLoginUrl;
use Pollora\Portcullis\Domain\Exception\InvalidLoginSlugException;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;
use Pollora\Portcullis\Domain\Model\ThrottleSettings;
use Pollora\Portcullis\Port\Out\AttemptStorePort;
use Pollora\Portcullis\Port\Out\FeatureTogglePort;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;
use Pollora\Portcullis\Port\Out\SlugProviderPort;
use Pollora\Portcullis\Port\Out\ThrottleSettingsPort;
use Throwable;

/**
 * Composition root: wires the adapters to the use cases and registers the hooks.
 *
 * Nothing has to call this. Requiring the package is enough — {@see Bootstrap}
 * is registered through Composer's `autoload.files` and schedules the boot on
 * its own. The method stays public for hosts that want to control the moment, or
 * to inject their own adapters:
 *
 * ```php
 * \Pollora\Portcullis\Portcullis::boot(store: new MyRedisAttemptStore());
 * ```
 *
 * The package is made of two features, booted independently so that either can
 * be used without the other:
 *
 * - **Hidden login** serves the login screen from `PORTCULLIS_LOGIN_SLUG`, and
 *   answers `wp-login.php` and anonymous `wp-admin/` requests with a 404. No slug
 *   configured leaves it dormant. That is a deliberate fail-open — a freshly
 *   provisioned environment, a restored dump or a missing `.env` entry must leave
 *   a site usable rather than lock everybody out of an installation nobody can
 *   reach a terminal on.
 * - **Brute-force protection** locks out addresses and accounts after repeated
 *   failed logins. Enabled by default, switched off with
 *   `PORTCULLIS_THROTTLE_ENABLED=false`.
 *
 * `PORTCULLIS_ENABLED` set to a falsy value switches both off. Enabled by
 * default: an installation that pulled the package in has opted in.
 */
final class Portcullis
{
    /**
     * Package version, exposed for host applications that report their stack.
     */
    public const VERSION = '2.0.0';

    /**
     * Guards against registering the hooks twice.
     *
     * The package can be reached both by the Composer bootstrap and by an
     * explicit call from a host, and duplicate filters would rewrite login URLs
     * twice over.
     */
    private static bool $booted = false;

    /**
     * Boots the package.
     *
     * @param  SlugProviderPort|null  $slugProvider  Where to read the slug from. Defaults to
     *                                               {@see EnvironmentSlugProvider}, which reads the
     *                                               `PORTCULLIS_LOGIN_SLUG` constant then the environment.
     * @param  FeatureTogglePort|null  $toggle  Where to read the kill switch from. Defaults to
     *                                          {@see EnvironmentFeatureToggle}.
     * @param  HookRegistrarPort|null  $hooks  Hook system to register against. Defaults to
     *                                         Pollora's `Action`/`Filter` layer when it is usable,
     *                                         and to the plain WordPress plugin API otherwise.
     * @param  ThrottleSettingsPort|null  $throttleSettings  Where to read the brute-force protection
     *                                                       settings from. Defaults to
     *                                                       {@see EnvironmentThrottleSettings}.
     * @param  AttemptStorePort|null  $store  Where failure counters are kept. Defaults to
     *                                        {@see WpdbAttemptStore}, a dedicated table.
     */
    public static function boot(
        ?SlugProviderPort $slugProvider = null,
        ?FeatureTogglePort $toggle = null,
        ?HookRegistrarPort $hooks = null,
        ?ThrottleSettingsPort $throttleSettings = null,
        ?AttemptStorePort $store = null,
    ): void {
        if (self::$booted) {
            return;
        }

        if (! ($toggle ?? new EnvironmentFeatureToggle)->state()->isEnabled()) {
            return;
        }

        self::$booted = true;

        $provider = $slugProvider ?? new EnvironmentSlugProvider;
        $registrar = $hooks ?? self::detectHookRegistrar();

        self::bootHiddenLogin($provider, $registrar);

        $command = self::bootThrottle($throttleSettings ?? new EnvironmentThrottleSettings, $store, $provider, $registrar)
            ?? new PortcullisCommand($provider);

        PortcullisCommand::register($command);
    }

    /**
     * Wires the hidden login screen, when a valid slug is configured.
     *
     * @param  SlugProviderPort  $provider  Source of the raw slug.
     * @param  HookRegistrarPort  $registrar  Hook system of the host.
     */
    private static function bootHiddenLogin(SlugProviderPort $provider, HookRegistrarPort $registrar): void
    {
        try {
            $slug = (new ResolveLoginSlug($provider))->resolve();
        } catch (InvalidLoginSlugException $exception) {
            self::reportMisconfiguration($exception, $registrar);

            return;
        }

        if ($slug === null) {
            return;
        }

        $context = new SuperglobalRequestContext($registrar);

        (new LoginUrlRewriter($slug, new RewriteLoginUrl, $registrar))->register();

        (new HiddenLoginRouter(
            $slug,
            $context,
            new MatchHiddenLoginRequest,
            new GuardDefaultEndpoints,
            new WpLoginScreenRenderer($context),
            new ThemeNotFoundResponder($context, $registrar),
            $registrar,
        ))->register();

        (new StockAliasRouter(new ClassifyStockAlias, $context, $registrar))->register();

        (new SlugCollisionNotice($slug, $registrar))->register();
    }

    /**
     * Wires the brute-force protection, when it is enabled.
     *
     * A rejected configuration does not switch the protection off: the defaults
     * apply, and the error is reported. Letting a typo in a threshold disable a
     * security control silently would be the worst of both worlds.
     *
     * @param  ThrottleSettingsPort  $settingsPort  Source of the settings.
     * @param  AttemptStorePort|null  $store  Injected store, `null` for the default table.
     * @param  SlugProviderPort  $provider  Source of the raw slug, for the CLI command.
     * @param  HookRegistrarPort  $registrar  Hook system of the host.
     * @return PortcullisCommand|null The CLI command wired with the protection, `null` when it is off.
     */
    private static function bootThrottle(
        ThrottleSettingsPort $settingsPort,
        ?AttemptStorePort $store,
        SlugProviderPort $provider,
        HookRegistrarPort $registrar,
    ): ?PortcullisCommand {
        if (! $settingsPort->isEnabled()) {
            return null;
        }

        try {
            $settings = $settingsPort->settings();
            $subjects = new DeriveSubjects($settings->secret());
        } catch (InvalidArgumentException $exception) {
            self::reportMisconfiguration($exception, $registrar);

            $settings = new ThrottleSettings(ThrottlePolicy::defaults(), [], [], EnvironmentThrottleSettings::derivedSecret(), true);
            $subjects = new DeriveSubjects($settings->secret());
        }

        $schema = null;

        if ($store === null) {
            if (! isset($GLOBALS['wpdb']) || ! $GLOBALS['wpdb'] instanceof \wpdb) {
                return null;
            }

            $schema = new WpdbSchema($GLOBALS['wpdb']);
            $store = new WpdbAttemptStore($GLOBALS['wpdb'], $schema);
        }

        $clock = new SystemClock;
        $accounts = new AccountIdentifier;
        $purge = new AttemptPurgeScheduler($store, $clock, $registrar);

        (new LoginThrottleGuard(
            $settings,
            new CheckLockout($store, $clock),
            new RecordFailedAttempt($store, $settings->policy(), $clock),
            new ClearAttempts($store),
            $subjects,
            new ResolveClientIp,
            new SuperglobalClientAddress,
            $accounts,
            new ErrorLogLockoutNotifier($registrar),
            $clock,
            $registrar,
        ))->register();

        $purge->register();

        if ($settings->genericErrors()) {
            (new GenericLoginErrors($registrar))->register();
        }

        return new PortcullisCommand($provider, $settings, $store, $subjects, $accounts, $clock, $purge, $schema);
    }

    /**
     * Picks the hook implementation that fits the host.
     *
     * On Pollora, hooks registered through the framework take part in its own
     * lifecycle; on anything else, the WordPress plugin API is the only option.
     */
    private static function detectHookRegistrar(): HookRegistrarPort
    {
        return PolloraHookRegistrar::isAvailable()
            ? new PolloraHookRegistrar
            : new WordPressHookRegistrar;
    }

    /**
     * Surfaces a configuration error without breaking the site.
     *
     * A rejected setting leaves the feature in a safe state — WordPress' stock
     * login for a bad slug, the default thresholds for bad thresholds — which is
     * also a silent one: hence both a log line for the operator and an admin
     * notice for whoever ends up wondering why the configuration is ignored.
     *
     * @param  Throwable  $exception  The validation failure.
     * @param  HookRegistrarPort  $hooks  Hook system of the host.
     */
    private static function reportMisconfiguration(Throwable $exception, HookRegistrarPort $hooks): void
    {
        error_log('[portcullis] '.$exception->getMessage());

        $hooks->addAction('admin_notices', static function () use ($exception): void {
            if (! current_user_can('manage_options')) {
                return;
            }

            printf(
                '<div class="notice notice-error"><p><strong>Portcullis</strong> — %s</p></div>',
                esc_html($exception->getMessage())
            );
        }, 10, 0);
    }
}
