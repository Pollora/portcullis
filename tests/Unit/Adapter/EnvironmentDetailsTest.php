<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentFeatureToggle;
use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentReader;
use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentSlugProvider;
use Pollora\Portcullis\Adapter\Out\WordPress\EnvironmentThrottleSettings;
use Pollora\Portcullis\Adapter\Out\WordPress\ErrorLogLockoutNotifier;
use Pollora\Portcullis\Adapter\Out\WordPress\SuperglobalRequestContext;
use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Adapter\Out\WordPress\WpLoginScreenRenderer;
use Pollora\Portcullis\Domain\Model\Lockout;
use Pollora\Portcullis\Domain\Model\LoginSlug;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Tests\Support\FakeRequestContext;
use Pollora\Portcullis\Tests\Support\RecordingHooks;
use Pollora\Portcullis\Tests\Support\Wp;

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

afterEach(function (): void {
    foreach (array_keys($_ENV + $_SERVER) as $key) {
        if (str_starts_with((string) $key, 'PORTCULLIS_TEST_')) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }
});

describe('EnvironmentReader details', function (): void {
    it('skips a blank string constant, as it skips a blank variable', function (): void {
        define('PORTCULLIS_TEST_CONST_BLANK', '   ');
        $_ENV['PORTCULLIS_TEST_NEXT_AFTER_BLANK'] = 'next';

        expect((new EnvironmentReader)->raw('PORTCULLIS_TEST_CONST_BLANK', 'PORTCULLIS_TEST_NEXT_AFTER_BLANK'))->toBe('next')
            ->and((new EnvironmentReader)->raw('PORTCULLIS_TEST_CONST_BLANK'))->toBeNull();
    });

    it('reads the server variables as well as the environment', function (): void {
        $_SERVER['PORTCULLIS_TEST_SRV'] = 'from-server';

        expect((new EnvironmentReader)->raw('PORTCULLIS_TEST_SRV'))->toBe('from-server');
    });

    it('reads a boolean constant as an integer, and an integer constant as a boolean', function (): void {
        define('PORTCULLIS_TEST_CONST_TRUE', true);
        define('PORTCULLIS_TEST_CONST_ONE', 1);
        define('PORTCULLIS_TEST_CONST_ZERO', 0);
        $reader = new EnvironmentReader;

        expect($reader->int('PORTCULLIS_TEST_CONST_TRUE', 9))->toBe(1)
            ->and($reader->bool('PORTCULLIS_TEST_CONST_ONE', false))->toBeTrue()
            ->and($reader->bool('PORTCULLIS_TEST_CONST_ZERO', true))->toBeFalse();
    });

    it('trims what it reads before interpreting it', function (): void {
        $_ENV['PORTCULLIS_TEST_PADDED_BOOL'] = '  Yes ';
        $_ENV['PORTCULLIS_TEST_PADDED_INT'] = ' -12 ';
        $reader = new EnvironmentReader;

        expect($reader->bool('PORTCULLIS_TEST_PADDED_BOOL', false))->toBeTrue()
            ->and($reader->int('PORTCULLIS_TEST_PADDED_INT', 0))->toBe(-12)
            ->and($reader->string('PORTCULLIS_TEST_PADDED_BOOL'))->toBe('Yes');
    });
});

describe('Environment lookups', function (): void {
    it('reads the slug and the kill switch from the server variables too', function (): void {
        $_SERVER['PORTCULLIS_TEST_SRV_SLUG'] = 'acces-serveur';
        $_SERVER['PORTCULLIS_TEST_SRV_SWITCH'] = 'off';

        expect((new EnvironmentSlugProvider('PORTCULLIS_TEST_SRV_SLUG'))->slug())->toBe('acces-serveur')
            ->and((new EnvironmentFeatureToggle('PORTCULLIS_TEST_SRV_SWITCH'))->state()->isEnabled())->toBeFalse();
    });

    it('listens to both of its own names by default', function (): void {
        $_ENV[EnvironmentSlugProvider::LEGACY_KEY] = 'acces-ancien';

        expect((new EnvironmentSlugProvider)->slug())->toBe('acces-ancien');

        $_ENV[EnvironmentSlugProvider::KEY] = 'acces-nouveau';

        expect((new EnvironmentSlugProvider)->slug())->toBe('acces-nouveau');

        unset($_ENV[EnvironmentSlugProvider::KEY], $_ENV[EnvironmentSlugProvider::LEGACY_KEY]);
    });

    it('switches off on either of its own names by default', function (string $key): void {
        $_ENV[$key] = 'false';

        expect((new EnvironmentFeatureToggle)->state()->isEnabled())->toBeFalse();

        unset($_ENV[$key]);
    })->with([EnvironmentFeatureToggle::KEY, EnvironmentFeatureToggle::LEGACY_KEY]);
});

describe('EnvironmentThrottleSettings::derivedSecret', function (): void {
    it('hashes the four WordPress keys, in order, under its own prefix', function (): void {
        foreach (['AUTH_KEY' => 'k1', 'AUTH_SALT' => 's1', 'SECURE_AUTH_KEY' => 'k2'] as $name => $value) {
            if (! defined($name)) {
                define($name, $value);
            }
        }

        if (! defined('SECURE_AUTH_SALT')) {
            // Not a string: it contributes nothing.
            define('SECURE_AUTH_SALT', 42);
        }

        $material = '';

        foreach (['AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT'] as $name) {
            $material .= is_string(constant($name)) ? constant($name) : '';
        }

        expect(EnvironmentThrottleSettings::derivedSecret())->toBe(hash('sha256', 'portcullis-subjects|'.$material));
    });
});

describe('ErrorLogLockoutNotifier', function (): void {
    it('logs at most a hundred characters of the login, with the odd ones masked and quotes escaped', function (): void {
        $log = tempnam(sys_get_temp_dir(), 'portcullis-log');
        $previous = ini_set('error_log', $log);
        $lockout = new Lockout(new Subject(SubjectScope::Account, hash('sha256', 'a', true)), 1_700_000_000, true);

        (new ErrorLogLockoutNotifier(new WordPressHookRegistrar))->lockedOut($lockout, clientIp('203.0.113.7'), str_repeat('a', 99)."\"\n\x01é".str_repeat('b', 60));
        (new ErrorLogLockoutNotifier(new WordPressHookRegistrar))->lockedOut($lockout, clientIp('203.0.113.7'), str_repeat('c', 150));

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        ini_set('error_log', (string) $previous);
        unlink($log);

        expect($lines)->toHaveCount(2)
            ->and($lines[0])->toContain('login="'.str_repeat('a', 99).'\\""')
            ->and($lines[0])->toEndWith('until=2023-11-14T22:13:20Z tier=long')
            ->and($lines[1])->toContain('login="'.str_repeat('c', 100).'" ')
            ->and($lines[1])->not->toContain(str_repeat('c', 101));
    });
});

describe('SuperglobalRequestContext details', function (): void {
    beforeEach(function (): void {
        $this->server = $_SERVER;
    });

    afterEach(function (): void {
        $_SERVER = $this->server;
    });

    it('has no request URI when the server gives none that is text', function (): void {
        unset($_SERVER['REQUEST_URI']);
        $context = new SuperglobalRequestContext(new RecordingHooks);

        $missing = $context->requestUri();
        $_SERVER['REQUEST_URI'] = ['x'];

        expect($missing)->toBe('')
            ->and($context->requestUri())->toBe('');
    });

    it('has no home path when the home URL has no path', function (): void {
        Wp::$home = 'https://example.test?x';

        expect((new SuperglobalRequestContext(new RecordingHooks))->homePath())->toBe('');
    });
});

describe('WpLoginScreenRenderer details', function (): void {
    it('keeps no question mark for an empty query', function (): void {
        $saved = [$_SERVER, $GLOBALS['pagenow'] ?? null];

        (new WpLoginScreenRenderer(new FakeRequestContext(uri: '/acces-secret/?')))->prepare(LoginSlug::fromString('acces-secret'));
        $uri = $_SERVER['REQUEST_URI'];
        [$_SERVER] = $saved;

        expect($uri)->toBe('/acces-secret');
    });
});
