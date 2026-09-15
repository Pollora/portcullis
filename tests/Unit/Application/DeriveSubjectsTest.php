<?php

declare(strict_types=1);

use Pollora\Portcullis\Application\Service\DeriveSubjects;
use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\SubjectScope;

const SECRET = 'a-secret-that-is-long-enough-to-be-accepted';

function derive(string $secret = SECRET): DeriveSubjects
{
    return new DeriveSubjects($secret);
}

it('derives stable keys', function (): void {
    $ip = ClientIp::tryFromString('203.0.113.7');

    expect(derive()->forIp($ip)->hexKey())->toBe(derive()->forIp($ip)->hexKey())
        ->and(derive()->forIp($ip)->hexKey())->toHaveLength(64);
});

it('never stores the address or the login in a recoverable form', function (): void {
    // The whole IPv4 space fits in a lookup table: only the secret keeps the
    // stored keys from being reversed.
    $ip = ClientIp::tryFromString('203.0.113.7');

    expect(derive()->forIp($ip)->hexKey())->not->toBe(hash('sha256', '203.0.113.7'))
        ->and(derive()->forIp($ip)->hexKey())->not->toBe(derive('another-secret-that-is-also-long-enough')->forIp($ip)->hexKey());
});

it('compares accounts case-insensitively', function (): void {
    expect(derive()->forAccount(' Admin ')->hexKey())->toBe(derive()->forAccount('admin')->hexKey());
});

it('keeps an address and a login spelling the same string apart', function (): void {
    $ip = ClientIp::tryFromString('203.0.113.7');

    expect(derive()->forIp($ip)->hexKey())->not->toBe(derive()->forAccount('203.0.113.7')->hexKey());
});

it('counts an attempt against the address and the account', function (): void {
    $subjects = derive()->forAttempt(ClientIp::tryFromString('203.0.113.7'), 'admin');

    expect(array_map(fn ($s) => $s->scope(), $subjects))->toBe([SubjectScope::Ip, SubjectScope::Account]);
});

it('leaves the account out when no login was submitted', function (): void {
    expect(derive()->forAttempt(ClientIp::tryFromString('203.0.113.7'), '  '))->toHaveCount(1);
});

it('refuses a secret too short to protect anything', function (): void {
    new DeriveSubjects('short');
})->throws(InvalidArgumentException::class);
