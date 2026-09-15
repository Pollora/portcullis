<?php

declare(strict_types=1);

use Pollora\Portcullis\Domain\Model\ClientIp;

it('accepts valid addresses in canonical form', function (string $raw, string $expected): void {
    expect(ClientIp::tryFromString($raw)?->value())->toBe($expected);
})->with([
    ['203.0.113.7', '203.0.113.7'],
    [' 203.0.113.7 ', '203.0.113.7'],
    ['2001:DB8:0:0:0:0:0:1', '2001:db8::1'],
]);

it('rejects anything that is not a bare address', function (string $raw): void {
    // Values reach this class from headers an attacker writes: anything that is
    // not strictly an address must not become a key of the attacker's choosing.
    expect(ClientIp::tryFromString($raw))->toBeNull();
})->with([
    [''],
    ['unknown'],
    ['203.0.113.7:4711'],
    ['[2001:db8::1]'],
    ['203.0.113.256'],
    ['203.0.113.7, 198.51.100.1'],
]);

it('groups IPv4 failures on the address itself', function (): void {
    expect(ClientIp::tryFromString('203.0.113.7')?->bucket())->toBe('203.0.113.7');
});

it('groups IPv6 failures on the /64', function (): void {
    // Rotating through the /64 a subscriber is handed must not reset the count.
    $a = ClientIp::tryFromString('2001:db8:1:2:aaaa::1');
    $b = ClientIp::tryFromString('2001:db8:1:2:ffff:ffff:ffff:ffff');
    $c = ClientIp::tryFromString('2001:db8:1:3::1');

    expect($a?->bucket())->toBe('2001:db8:1:2::/64')
        ->and($b?->bucket())->toBe($a?->bucket())
        ->and($c?->bucket())->not->toBe($a?->bucket());
});
