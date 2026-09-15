<?php

declare(strict_types=1);

namespace Pollora\HiddenLogin;

use Pollora\Portcullis\Port\Out\FeatureTogglePort;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;
use Pollora\Portcullis\Port\Out\SlugProviderPort;
use Pollora\Portcullis\Portcullis;

/**
 * Entry point of `pollora/hidden-login` 1.x, kept so that hosts calling it keep booting.
 *
 * The package was renamed `pollora/portcullis` when it grew beyond hiding the
 * login screen. Hosts that never called the composition root themselves have
 * nothing to change; the others should move to {@see Portcullis::boot()}.
 *
 * @deprecated 2.0.0 Use {@see Portcullis} instead.
 */
final class HiddenLogin
{
    /**
     * Version of the package that replaced this one.
     *
     * @deprecated 2.0.0 Use {@see Portcullis::VERSION} instead.
     */
    public const VERSION = Portcullis::VERSION;

    /**
     * Boots the package with the 1.x signature.
     *
     * @deprecated 2.0.0 Use {@see Portcullis::boot()} instead.
     *
     * @param  SlugProviderPort|null  $slugProvider  Where to read the slug from.
     * @param  FeatureTogglePort|null  $toggle  Where to read the kill switch from.
     * @param  HookRegistrarPort|null  $hooks  Hook system to register against.
     */
    public static function boot(
        ?SlugProviderPort $slugProvider = null,
        ?FeatureTogglePort $toggle = null,
        ?HookRegistrarPort $hooks = null,
    ): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '2.0.0', Portcullis::class.'::boot()');
        }

        Portcullis::boot(slugProvider: $slugProvider, toggle: $toggle, hooks: $hooks);
    }
}
