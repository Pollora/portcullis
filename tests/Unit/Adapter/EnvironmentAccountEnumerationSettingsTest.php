<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentAccountEnumerationSettings;
use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;

afterEach(function (): void {
    unset(
        $_ENV[EnvironmentAccountEnumerationSettings::HIDE_ACCOUNTS],
        $_ENV[EnvironmentAccountEnumerationSettings::AUTHOR_ARCHIVES],
    );
});

it('hides accounts and keeps archives that list posts public by default', function (): void {
    $settings = new EnvironmentAccountEnumerationSettings;

    expect($settings->isEnabled())->toBeTrue()
        ->and($settings->authorArchivePolicy())->toBe(AuthorArchivePolicy::PublicWithPosts);
});

it('leaves archives to WordPress once the protection is off', function (): void {
    $_ENV[EnvironmentAccountEnumerationSettings::HIDE_ACCOUNTS] = 'false';

    $settings = new EnvironmentAccountEnumerationSettings;

    expect($settings->isEnabled())->toBeFalse()
        ->and($settings->authorArchivePolicy())->toBe(AuthorArchivePolicy::Untouched);
});

it('closes archives whether the protection is on or off', function (string $hideAccounts): void {
    $_ENV[EnvironmentAccountEnumerationSettings::HIDE_ACCOUNTS] = $hideAccounts;
    $_ENV[EnvironmentAccountEnumerationSettings::AUTHOR_ARCHIVES] = 'false';

    expect((new EnvironmentAccountEnumerationSettings)->authorArchivePolicy())->toBe(AuthorArchivePolicy::Closed);
})->with(['true', 'false']);

it('keeps archives public on an unrecognised value', function (): void {
    $_ENV[EnvironmentAccountEnumerationSettings::AUTHOR_ARCHIVES] = 'nope';

    expect((new EnvironmentAccountEnumerationSettings)->authorArchivePolicy())->toBe(AuthorArchivePolicy::PublicWithPosts);
});

it('reads the documented keys', function (): void {
    expect(EnvironmentAccountEnumerationSettings::HIDE_ACCOUNTS)->toBe('PORTCULLIS_HIDE_ACCOUNTS')
        ->and(EnvironmentAccountEnumerationSettings::AUTHOR_ARCHIVES)->toBe('PORTCULLIS_AUTHOR_ARCHIVES');
});
