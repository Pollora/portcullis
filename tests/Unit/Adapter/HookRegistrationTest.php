<?php

declare(strict_types=1);

use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;
use Pollora\Portcullis\Domain\Model\ScopePolicy;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;
use Pollora\Portcullis\Domain\Model\ThrottleSettings;
use Pollora\Portcullis\Port\Out\FeatureTogglePort;
use Pollora\Portcullis\Port\Out\ThrottleSettingsPort;
use Pollora\Portcullis\Portcullis;
use Pollora\Portcullis\Tests\Support\FixedEnumerationSettings;
use Pollora\Portcullis\Tests\Support\InMemorySlugProvider;
use Pollora\Portcullis\Tests\Support\RecordingHooks;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

/**
 * Every registration made on the fake plugin API, as "priority/accepted args/callback".
 *
 * @return array<string, list<string>>
 */
function registrations(): array
{
    $all = [];

    foreach (Wp::$hooks as $hook => $byPriority) {
        ksort($byPriority);

        foreach ($byPriority as $priority => $callbacks) {
            foreach ($callbacks as [$callback, $accepted]) {
                $name = is_array($callback) ? $callback[1] : 'closure';
                $all[$hook][] = sprintf('%s/%d/%s', $priority === PHP_INT_MAX ? 'MAX' : ($priority === PHP_INT_MIN ? 'MIN' : $priority), $accepted, $name);
            }
        }
    }

    ksort($all);

    return $all;
}

function bootEverything(AuthorArchivePolicy $archives): void
{
    $GLOBALS['wpdb'] = new wpdb;

    $toggle = new class implements FeatureTogglePort
    {
        public function state(): Pollora\Portcullis\Domain\Model\FeatureState
        {
            return Pollora\Portcullis\Domain\Model\FeatureState::Enabled;
        }
    };

    $throttle = new class implements ThrottleSettingsPort
    {
        public function isEnabled(): bool
        {
            return true;
        }

        public function settings(): ThrottleSettings
        {
            return new ThrottleSettings(
                new ThrottlePolicy(3600, new ScopePolicy(3, 600, 2, 7200), null),
                [],
                [],
                'a-secret-long-enough-for-the-subject-derivation',
                true,
            );
        }
    };

    (new ReflectionProperty(Portcullis::class, 'booted'))->setValue(null, false);

    Portcullis::boot(
        slugProvider: new InMemorySlugProvider('acces-secret'),
        toggle: $toggle,
        hooks: new RecordingHooks,
        throttleSettings: $throttle,
        enumerationSettings: new FixedEnumerationSettings(true, $archives),
    );
}

it('registers every hook with its priority and accepted arguments', function (): void {
    bootEverything(AuthorArchivePolicy::Closed);

    expect(registrations())->toBe([
        'admin_init' => ['1/0/install'],
        'admin_notices' => ['10/0/render', '10/0/render'],
        'application_password_failed_authentication' => ['10/1/recordApplicationPasswordFailure'],
        'authenticate' => ['MIN/3/refuseLockedOut', 'MAX/3/recordOutcome'],
        'author_link' => ['MAX/2/filterAuthorLink'],
        'comment_class' => ['MAX/4/filterCommentClasses'],
        'determine_current_user' => ['19/1/refuseLockedOutApplicationPassword'],
        'init' => ['10/0/schedule'],
        'network_admin_notices' => ['10/0/render'],
        'network_site_url' => ['10/3/filterNetworkSiteUrl'],
        'oembed_response_data' => ['MAX/1/filterOembedData'],
        'plugins_loaded' => ['1/0/route'],
        'portcullis_purge_attempts' => ['10/0/purge'],
        'pre_handle_404' => ['MAX/2/filterNotFound'],
        'rest_endpoints' => ['MAX/1/filterRoutes'],
        'shake_error_codes' => ['10/1/shakeOnLockout'],
        'site_url' => ['10/4/filterSiteUrl'],
        'template_redirect' => ['1000/0/route'],
        'wp_login' => ['1/2/clearAccount'],
        'wp_login_errors' => ['MAX/1/filter'],
        'wp_login_failed' => ['10/2/recordLoginFailed'],
        'wp_redirect' => ['10/1/filterRedirect'],
        'wp_sitemaps_add_provider' => ['MAX/2/filterSitemapProvider'],
    ]);
});
