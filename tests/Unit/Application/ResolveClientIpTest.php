<?php

declare(strict_types=1);

use Pollora\Portcullis\Application\Service\ResolveClientIp;
use Pollora\Portcullis\Domain\Model\IpRange;

function resolveIp(string $remote, ?string $forwardedFor, string $trusted = ''): ?string
{
    return (new ResolveClientIp)->resolve($remote, $forwardedFor, IpRange::listFromString($trusted))?->value();
}

it('uses the peer address when no proxy is trusted', function (): void {
    // The default: a forged header must change nothing.
    expect(resolveIp('198.51.100.9', '203.0.113.7'))->toBe('198.51.100.9');
});

it('ignores the header when the peer is not a trusted proxy', function (): void {
    expect(resolveIp('198.51.100.9', '203.0.113.7', '10.0.0.0/8'))->toBe('198.51.100.9');
});

it('believes the hop appended by a trusted proxy', function (): void {
    expect(resolveIp('10.0.0.2', '203.0.113.7', '10.0.0.0/8'))->toBe('203.0.113.7');
});

it('walks the header from the right, past every trusted proxy', function (): void {
    expect(resolveIp('10.0.0.2', '203.0.113.7, 10.0.0.5, 10.0.0.3', '10.0.0.0/8'))->toBe('203.0.113.7');
});

it('cannot be fooled by addresses the client prepends', function (): void {
    // The client writes whatever it wants at the left of the header; the proxy
    // appends the real address at the right. Reading from the left, as Limit
    // Login Attempts Reloaded does, would hand the attacker a fresh identity on
    // every request.
    expect(resolveIp('10.0.0.2', '1.2.3.4, 5.6.7.8, 203.0.113.7', '10.0.0.0/8'))->toBe('203.0.113.7');
});

it('stops at a hop no trusted proxy would have written', function (): void {
    expect(resolveIp('10.0.0.2', 'garbage, 10.0.0.5', '10.0.0.0/8'))->toBe('10.0.0.5')
        ->and(resolveIp('10.0.0.2', 'garbage', '10.0.0.0/8'))->toBe('10.0.0.2');
});

it('returns the leftmost hop when every hop is trusted', function (): void {
    expect(resolveIp('10.0.0.2', '10.0.0.9, 10.0.0.5', '10.0.0.0/8'))->toBe('10.0.0.9');
});

it('strips ports from hops', function (string $hop, string $expected): void {
    expect(resolveIp('10.0.0.2', $hop, '10.0.0.0/8'))->toBe($expected);
})->with([
    ['203.0.113.7:4711', '203.0.113.7'],
    ['[2001:db8::7]:4711', '2001:db8::7'],
    ['[2001:db8::7]', '2001:db8::7'],
    ['2001:db8::7', '2001:db8::7'],
]);

it('returns null when the peer address is unusable', function (): void {
    expect(resolveIp('', null))->toBeNull()
        ->and(resolveIp('unknown', '203.0.113.7', '0.0.0.0/0'))->toBeNull();
});
