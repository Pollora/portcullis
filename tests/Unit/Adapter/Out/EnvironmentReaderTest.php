<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentReader;
use Pollora\Portcullis\Domain\Exception\InvalidThrottlePolicyException;

/*
 * Constants cannot be undefined once declared: each test that needs one uses a
 * name of its own. Everything else goes through `$_ENV`.
 */

afterEach(function (): void {
    foreach (array_keys($_ENV) as $key) {
        if (str_starts_with((string) $key, 'PORTCULLIS_TEST_')) {
            unset($_ENV[$key]);
        }
    }
});

it('returns the first key holding a value', function (): void {
    $_ENV['PORTCULLIS_TEST_B'] = 'second';

    expect((new EnvironmentReader)->raw('PORTCULLIS_TEST_A', 'PORTCULLIS_TEST_B'))->toBe('second');
});

it('treats a blank value as unset and moves on to the next key', function (): void {
    $_ENV['PORTCULLIS_TEST_A'] = '   ';
    $_ENV['PORTCULLIS_TEST_B'] = 'fallback';

    expect((new EnvironmentReader)->raw('PORTCULLIS_TEST_A', 'PORTCULLIS_TEST_B'))->toBe('fallback');
});

it('returns null when no key is configured', function (): void {
    expect((new EnvironmentReader)->raw('PORTCULLIS_TEST_NOPE'))->toBeNull()
        ->and((new EnvironmentReader)->string('PORTCULLIS_TEST_NOPE'))->toBeNull();
});

it('prefers a constant over the environment', function (): void {
    define('PORTCULLIS_TEST_CONST_WINS', 'from-constant');
    $_ENV['PORTCULLIS_TEST_CONST_WINS'] = 'from-env';

    expect((new EnvironmentReader)->raw('PORTCULLIS_TEST_CONST_WINS'))->toBe('from-constant');
});

it('ignores a constant defined as null and does not fall back to the environment for that key', function (): void {
    // `Config::define(..., env(...))` yields null for an absent variable: the
    // key counts as unset, and the next key is consulted instead.
    define('PORTCULLIS_TEST_CONST_NULL', null);
    $_ENV['PORTCULLIS_TEST_CONST_NULL'] = 'from-env';
    $_ENV['PORTCULLIS_TEST_NEXT'] = 'next';

    expect((new EnvironmentReader)->raw('PORTCULLIS_TEST_CONST_NULL', 'PORTCULLIS_TEST_NEXT'))->toBe('next');
});

it('accepts boolean and integer constants as they are', function (): void {
    define('PORTCULLIS_TEST_CONST_BOOL', false);
    define('PORTCULLIS_TEST_CONST_INT', 7);

    $reader = new EnvironmentReader;

    expect($reader->raw('PORTCULLIS_TEST_CONST_BOOL'))->toBeFalse()
        ->and($reader->raw('PORTCULLIS_TEST_CONST_INT'))->toBe(7);
});

it('trims a string value', function (): void {
    $_ENV['PORTCULLIS_TEST_STR'] = '  padded  ';

    expect((new EnvironmentReader)->string('PORTCULLIS_TEST_STR'))->toBe('padded');
});

it('returns null as a string when the constant holds a non-string', function (): void {
    define('PORTCULLIS_TEST_CONST_INT_AS_STRING', 3);

    expect((new EnvironmentReader)->string('PORTCULLIS_TEST_CONST_INT_AS_STRING'))->toBeNull();
});

it('reads integers, with a default when unset', function (): void {
    $_ENV['PORTCULLIS_TEST_INT'] = ' -12 ';
    $reader = new EnvironmentReader;

    expect($reader->int('PORTCULLIS_TEST_INT', 5))->toBe(-12)
        ->and($reader->int('PORTCULLIS_TEST_INT_ABSENT', 5))->toBe(5);
});

it('reads an integer constant', function (): void {
    define('PORTCULLIS_TEST_CONST_INT_READ', 42);

    expect((new EnvironmentReader)->int('PORTCULLIS_TEST_CONST_INT_READ', 1))->toBe(42);
});

it('refuses a value that is not an integer rather than guessing a threshold', function (string $value): void {
    $_ENV['PORTCULLIS_TEST_BAD_INT'] = $value;

    (new EnvironmentReader)->int('PORTCULLIS_TEST_BAD_INT', 5);
})->with(['3.5', 'five', '5 attempts', '1e3'])->throws(InvalidThrottlePolicyException::class, 'must be an integer');

it('reads booleans from the usual spellings', function (string $value, bool $expected): void {
    $_ENV['PORTCULLIS_TEST_BOOL'] = $value;

    expect((new EnvironmentReader)->bool('PORTCULLIS_TEST_BOOL', ! $expected))->toBe($expected);
})->with([
    ['true', true], ['1', true], ['ON', true], ['Yes', true], ['enabled', true],
    ['false', false], ['0', false], ['off', false], ['NO', false], ['disabled', false],
]);

it('falls back to the default on an unrecognised boolean, in either direction', function (): void {
    $_ENV['PORTCULLIS_TEST_BOOL'] = 'maybe';
    $reader = new EnvironmentReader;

    expect($reader->bool('PORTCULLIS_TEST_BOOL', true))->toBeTrue()
        ->and($reader->bool('PORTCULLIS_TEST_BOOL', false))->toBeFalse()
        ->and($reader->bool('PORTCULLIS_TEST_BOOL_ABSENT', true))->toBeTrue();
});

it('returns a boolean constant as it is', function (): void {
    define('PORTCULLIS_TEST_CONST_BOOL_READ', false);

    expect((new EnvironmentReader)->bool('PORTCULLIS_TEST_CONST_BOOL_READ', true))->toBeFalse();
});
