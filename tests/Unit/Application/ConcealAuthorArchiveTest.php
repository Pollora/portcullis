<?php

declare(strict_types=1);

use Pollora\Portcullis\Application\Service\ConcealAuthorArchive;
use Pollora\Portcullis\Domain\Model\AuthorArchivePolicy;
use Pollora\Portcullis\Domain\Model\AuthorArchiveUrl;

it('conceals the identifier shortcut whatever the archive lists', function (AuthorArchivePolicy $policy): void {
    expect((new ConcealAuthorArchive($policy))->conceals(AuthorArchiveUrl::Shortcut, 3))->toBeTrue();
})->with([
    'archives public' => [AuthorArchivePolicy::PublicWithPosts],
    'archives closed' => [AuthorArchivePolicy::Closed],
]);

it('keeps a public archive that lists posts', function (): void {
    expect((new ConcealAuthorArchive(AuthorArchivePolicy::PublicWithPosts))->conceals(AuthorArchiveUrl::Canonical, 3))
        ->toBeFalse();
});

it('conceals a public archive that lists nothing', function (): void {
    expect((new ConcealAuthorArchive(AuthorArchivePolicy::PublicWithPosts))->conceals(AuthorArchiveUrl::Canonical, 0))
        ->toBeTrue();
});

it('conceals every archive once they are closed', function (): void {
    expect((new ConcealAuthorArchive(AuthorArchivePolicy::Closed))->conceals(AuthorArchiveUrl::Canonical, 3))
        ->toBeTrue();
});

it('conceals nothing when archives are left to WordPress', function (AuthorArchiveUrl $url): void {
    expect((new ConcealAuthorArchive(AuthorArchivePolicy::Untouched))->conceals($url, 0))->toBeFalse();
})->with([
    'the identifier shortcut' => [AuthorArchiveUrl::Shortcut],
    'the canonical URL' => [AuthorArchiveUrl::Canonical],
]);
