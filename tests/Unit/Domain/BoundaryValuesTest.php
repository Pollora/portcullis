<?php

declare(strict_types=1);

use Pollora\Portcullis\Application\Service\DeriveSubjects;
use Pollora\Portcullis\Application\Service\RecordFailedAttempt;
use Pollora\Portcullis\Application\Service\ResolveClientIp;
use Pollora\Portcullis\Application\Service\RewriteLoginUrl;
use Pollora\Portcullis\Domain\Exception\InvalidIpRangeException;
use Pollora\Portcullis\Domain\Exception\InvalidLoginSlugException;
use Pollora\Portcullis\Domain\Exception\InvalidThrottlePolicyException;
use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\IpRange;
use Pollora\Portcullis\Domain\Model\LoginSlug;
use Pollora\Portcullis\Domain\Model\ScopePolicy;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;
use Pollora\Portcullis\Tests\Support\FrozenClock;
use Pollora\Portcullis\Tests\Support\InMemoryAttemptStore;

describe('ScopePolicy limits', function (): void {
    it('accepts the smallest value of every threshold', function (): void {
        $policy = new ScopePolicy(1, 1, 1, 1);

        expect($policy->maxRetries())->toBe(1)
            ->and($policy->lockoutDuration())->toBe(1)
            ->and($policy->maxLockouts())->toBe(1)
            ->and($policy->longLockoutDuration())->toBe(1);
    });

    it('rejects zero retries and a zero lockout duration', function (): void {
        expect(fn () => new ScopePolicy(0, 1, 0, 0))->toThrow(InvalidThrottlePolicyException::class)
            ->and(fn () => new ScopePolicy(1, 0, 0, 0))->toThrow(InvalidThrottlePolicyException::class);
    });

    it('accepts a long lockout exactly as long as the regular one, and rejects a shorter one', function (): void {
        expect((new ScopePolicy(3, 600, 2, 600))->longLockoutDuration())->toBe(600)
            ->and(fn () => new ScopePolicy(3, 600, 2, 599))->toThrow(InvalidThrottlePolicyException::class);
    });

    it('checks the long duration as soon as a single lockout is allowed', function (): void {
        expect(fn () => new ScopePolicy(3, 600, 1, 599))->toThrow(InvalidThrottlePolicyException::class);
    });

    it('does not check the long duration when the long tier is off', function (): void {
        expect((new ScopePolicy(3, 600, 0, 0))->isLongLockout(5))->toBeFalse();
    });

    it('makes the very first lockout long when only one lockout is allowed', function (): void {
        $policy = new ScopePolicy(3, 600, 1, 7200);

        expect($policy->isLongLockout(0))->toBeTrue()
            ->and((new ScopePolicy(3, 600, 2, 7200))->isLongLockout(0))->toBeFalse()
            ->and((new ScopePolicy(3, 600, 2, 7200))->isLongLockout(1))->toBeTrue();
    });
});

describe('ThrottlePolicy limits', function (): void {
    it('accepts a validity window exactly as long as a lockout', function (): void {
        expect((new ThrottlePolicy(600, new ScopePolicy(3, 600, 0, 0), new ScopePolicy(5, 600, 0, 0)))->retriesValidity())->toBe(600);
    });

    it('rejects a validity window shorter than the account lockout as well', function (): void {
        expect(fn () => new ThrottlePolicy(600, new ScopePolicy(3, 600, 0, 0), new ScopePolicy(5, 601, 0, 0)))
            ->toThrow(InvalidThrottlePolicyException::class)
            ->and(fn () => new ThrottlePolicy(600, new ScopePolicy(3, 601, 0, 0), null))
            ->toThrow(InvalidThrottlePolicyException::class);
    });
});

describe('IpRange parsing', function (): void {
    it('accepts the longest prefix of each family', function (string $range, string $notation): void {
        expect((string) IpRange::fromString($range))->toBe($notation);
    })->with([
        ['203.0.113.7/32', '203.0.113.7/32'],
        ['2001:db8::1/128', '2001:db8::1/128'],
    ]);

    it('rejects a prefix past the longest one, and a second slash', function (string $range): void {
        expect(fn () => IpRange::fromString($range))->toThrow(InvalidIpRangeException::class);
    })->with(['203.0.113.7/33', '2001:db8::1/129', '10.0.0.0/8/9', '10.0.0.0/8/']);

    it('accepts spaces around the notation, not inside the prefix', function (): void {
        expect((string) IpRange::fromString('  203.0.113.0/24  '))->toBe('203.0.113.0/24')
            ->and(fn () => IpRange::fromString('203.0.113.0/ 24'))->toThrow(InvalidIpRangeException::class);
    });

    it('masks the bits past the prefix, byte by byte', function (): void {
        $range = IpRange::fromString('10.255.255.255/12');

        expect($range->contains(ClientIp::tryFromString('10.240.0.1')))->toBeTrue()
            ->and($range->contains(ClientIp::tryFromString('10.239.255.255')))->toBeFalse()
            ->and($range->contains(ClientIp::tryFromString('10.255.255.255')))->toBeTrue()
            ->and($range->contains(ClientIp::tryFromString('11.240.0.1')))->toBeFalse();
    });

    it('never matches an address of the other family', function (): void {
        expect(IpRange::fromString('0.0.0.0/0')->contains(ClientIp::tryFromString('::1')))->toBeFalse()
            ->and(IpRange::fromString('::/0')->contains(ClientIp::tryFromString('10.0.0.1')))->toBeFalse();
    });
});

describe('LoginSlug normalisation', function (): void {
    it('trims whitespace left inside the slashes', function (): void {
        expect(LoginSlug::fromString('/ acces-secret /')->value())->toBe('acces-secret');
    });

    it('says the slug is empty, not malformed, when nothing is left', function (string $raw): void {
        expect(fn () => LoginSlug::fromString($raw))->toThrow(InvalidLoginSlugException::class, 'The login slug is empty once trimmed of slashes and whitespace.');
    })->with(['', '   ', '/', ' / ', '//']);
});

describe('DeriveSubjects', function (): void {
    it('accepts a secret of exactly the minimum length', function (): void {
        expect(new DeriveSubjects(str_repeat('a', DeriveSubjects::MINIMUM_SECRET_LENGTH)))->toBeInstanceOf(DeriveSubjects::class)
            ->and(fn () => new DeriveSubjects(str_repeat('a', DeriveSubjects::MINIMUM_SECRET_LENGTH - 1)))
            ->toThrow(InvalidArgumentException::class, 'The secret subjects are derived with must be at least 32 bytes long.');
    });

    it('keys a subject with the scope, a NUL byte and the value, under the secret', function (): void {
        $secret = str_repeat('s', 40);
        $derive = new DeriveSubjects($secret);

        expect($derive->forAccount('Bob')->hexKey())->toBe(hash_hmac('sha256', SubjectScope::Account->label()."\x00bob", $secret))
            ->and($derive->forIp(ClientIp::tryFromString('203.0.113.7'))->hexKey())->toBe(hash_hmac('sha256', SubjectScope::Ip->label()."\x00203.0.113.7", $secret));
    });
});

describe('ResolveClientIp', function (): void {
    it('strips spaces around a hop that carries a port', function (string $hop, string $expected): void {
        expect((new ResolveClientIp)->resolve('10.0.0.2', $hop, IpRange::listFromString('10.0.0.0/8'))?->value())->toBe($expected);
    })->with([
        ['  203.0.113.7:4711', '203.0.113.7'],
        [' [2001:db8::1]:4711 ', '2001:db8::1'],
    ]);
});

describe('RewriteLoginUrl', function (): void {
    it('keeps no empty query or fragment', function (string $url, string $expected): void {
        expect((new RewriteLoginUrl)->rewrite($url, 'https://example.com', LoginSlug::fromString('acces-prive')))->toBe($expected);
    })->with([
        ['https://example.com/wp/wp-login.php?', 'https://example.com/acces-prive'],
        ['https://example.com/wp/wp-login.php#', 'https://example.com/acces-prive'],
        ['https://example.com/wp/wp-login.php?a=1#top', 'https://example.com/acces-prive?a=1#top'],
    ]);
});

describe('RecordFailedAttempt', function (): void {
    it('goes on to the next subject after one whose scope is not counted', function (): void {
        $store = new InMemoryAttemptStore;
        $policy = new ThrottlePolicy(3600, new ScopePolicy(1, 600, 0, 0), null);
        $account = new Subject(SubjectScope::Account, hash('sha256', 'account', true));
        $ip = new Subject(SubjectScope::Ip, hash('sha256', 'ip', true));

        $lockouts = (new RecordFailedAttempt($store, $policy, new FrozenClock))->record([$account, $ip]);

        expect($lockouts)->toHaveCount(1)
            ->and($store->count())->toBe(1);
    });
});
