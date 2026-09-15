<?php

declare(strict_types=1);

use Pollora\Portcullis\Domain\Exception\InvalidIpRangeException;
use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\IpRange;

function ip(string $value): ClientIp
{
    $ip = ClientIp::tryFromString($value);

    if ($ip === null) {
        throw new LogicException("Invalid test address {$value}");
    }

    return $ip;
}

it('matches addresses inside a network', function (string $range, string $address, bool $expected): void {
    expect(IpRange::fromString($range)->contains(ip($address)))->toBe($expected);
})->with([
    ['203.0.113.0/24', '203.0.113.0', true],
    ['203.0.113.0/24', '203.0.113.255', true],
    ['203.0.113.0/24', '203.0.114.1', false],
    ['10.0.0.0/8', '10.255.1.2', true],
    ['172.16.0.0/12', '172.31.255.255', true],
    ['172.16.0.0/12', '172.32.0.1', false],
    ['203.0.113.7', '203.0.113.7', true],
    ['203.0.113.7', '203.0.113.8', false],
    ['0.0.0.0/0', '198.51.100.1', true],
    ['2001:db8::/32', '2001:db8:ffff::1', true],
    ['2001:db8::/32', '2001:db9::1', false],
    ['2001:db8::/33', '2001:db8:8000::1', false],
]);

it('normalises a network written with host bits set', function (): void {
    expect(IpRange::fromString('203.0.113.77/24')->contains(ip('203.0.113.1')))->toBeTrue()
        ->and((string) IpRange::fromString('203.0.113.77/24'))->toBe('203.0.113.77/24');
});

it('never matches across address families', function (): void {
    expect(IpRange::fromString('0.0.0.0/0')->contains(ip('::1')))->toBeFalse()
        ->and(IpRange::fromString('::/0')->contains(ip('127.0.0.1')))->toBeFalse();
});

it('rejects malformed ranges', function (string $value): void {
    IpRange::fromString($value);
})->with([
    ['not-an-ip'],
    ['203.0.113.0/33'],
    ['2001:db8::/129'],
    ['203.0.113.0/'],
    ['203.0.113.0/abc'],
])->throws(InvalidIpRangeException::class);

it('parses a list separated by commas or whitespace', function (): void {
    $ranges = IpRange::listFromString(" 10.0.0.0/8,  192.168.0.1\n2001:db8::/32 ");

    expect(array_map('strval', $ranges))->toBe(['10.0.0.0/8', '192.168.0.1', '2001:db8::/32'])
        ->and(IpRange::listFromString('   '))->toBe([]);
});
