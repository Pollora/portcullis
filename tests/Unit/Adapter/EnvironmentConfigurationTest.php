<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentFeatureToggle;
use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentSlugProvider;

/*
 * Constants cannot be undefined once declared, so these tests go through
 * `$_ENV` with names private to each test. The lookup order between constant
 * and environment is the same for every key.
 */

afterEach(function (): void {
    foreach (array_keys($_ENV) as $key) {
        if (str_starts_with((string) $key, 'PORTCULLIS_TEST_')) {
            unset($_ENV[$key]);
        }
    }
});

it('reads the slug from the first key that holds a value', function (): void {
    $_ENV['PORTCULLIS_TEST_NEW_SLUG'] = 'acces-portcullis';
    $_ENV['PORTCULLIS_TEST_OLD_SLUG'] = 'acces-legacy';

    $provider = new EnvironmentSlugProvider('PORTCULLIS_TEST_NEW_SLUG', 'PORTCULLIS_TEST_OLD_SLUG');

    expect($provider->slug())->toBe('acces-portcullis');
});

it('falls back to the 1.x key when the new one is absent', function (): void {
    // An installation upgraded from pollora/hidden-login keeps its `.env` as it
    // was: the rename must not silently switch the protection off.
    $_ENV['PORTCULLIS_TEST_OLD_SLUG'] = 'acces-legacy';

    $provider = new EnvironmentSlugProvider('PORTCULLIS_TEST_NEW_SLUG', 'PORTCULLIS_TEST_OLD_SLUG');

    expect($provider->slug())->toBe('acces-legacy');
});

it('skips a blank value in favour of the next key', function (): void {
    $_ENV['PORTCULLIS_TEST_NEW_SLUG'] = '   ';
    $_ENV['PORTCULLIS_TEST_OLD_SLUG'] = 'acces-legacy';

    $provider = new EnvironmentSlugProvider('PORTCULLIS_TEST_NEW_SLUG', 'PORTCULLIS_TEST_OLD_SLUG');

    expect($provider->slug())->toBe('acces-legacy');
});

it('looks up the new key before the 1.x one by default', function (): void {
    expect(EnvironmentSlugProvider::KEY)->toBe('PORTCULLIS_LOGIN_SLUG')
        ->and(EnvironmentSlugProvider::LEGACY_KEY)->toBe('HIDDEN_LOGIN_SLUG')
        ->and(EnvironmentFeatureToggle::KEY)->toBe('PORTCULLIS_ENABLED')
        ->and(EnvironmentFeatureToggle::LEGACY_KEY)->toBe('HIDDEN_LOGIN_ENABLED');
});

it('honours a 1.x kill switch', function (): void {
    $_ENV['PORTCULLIS_TEST_OLD_ENABLED'] = 'false';

    $toggle = new EnvironmentFeatureToggle('PORTCULLIS_TEST_NEW_ENABLED', 'PORTCULLIS_TEST_OLD_ENABLED');

    expect($toggle->state()->isEnabled())->toBeFalse();
});

it('lets the new kill switch override the 1.x one', function (): void {
    $_ENV['PORTCULLIS_TEST_NEW_ENABLED'] = 'true';
    $_ENV['PORTCULLIS_TEST_OLD_ENABLED'] = 'false';

    $toggle = new EnvironmentFeatureToggle('PORTCULLIS_TEST_NEW_ENABLED', 'PORTCULLIS_TEST_OLD_ENABLED');

    expect($toggle->state()->isEnabled())->toBeTrue();
});
