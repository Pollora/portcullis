<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\In\WordPress\AuthorLinkRewriter;
use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
use Pollora\Portcullis\Adapter\Out\WordPress\WpdbAttemptStore;
use Pollora\Portcullis\Adapter\Out\WordPress\WpdbSchema;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Tests\Support\Wp;

/*
 * What the adapters do with a value WordPress or the database should never hand
 * them: it counts for nothing, instead of whatever PHP would cast it to.
 */

if (is_string(getenv('PORTCULLIS_WP_LOAD')) && getenv('PORTCULLIS_WP_LOAD') !== '') {
    return;
}

it('reads a counter column that is not numeric as 0', function (): void {
    Wp::$options[WpdbSchema::VERSION_OPTION] = WpdbSchema::VERSION;
    $wpdb = new wpdb;
    $wpdb->rowResults = [['failures' => '3abc', 'lockouts' => 'x', 'locked_until' => '9000zz', 'window_expires_at' => '7000']];
    $store = new WpdbAttemptStore($wpdb, new WpdbSchema($wpdb));

    $state = $store->recordFailure(new Subject(SubjectScope::Ip, hash('sha256', 'a', true)), 1_000, 7_000);

    expect($state->failures())->toBe(0)
        ->and($state->lockouts())->toBe(0)
        ->and($state->lockedUntil())->toBe(0)
        ->and($state->windowExpiresAt())->toBe(7000);
});

it('does not take a schema version that only starts with the current one', function (): void {
    Wp::$options[WpdbSchema::VERSION_OPTION] = WpdbSchema::VERSION.'abc';

    expect((new WpdbSchema(new wpdb))->isUpToDate())->toBeFalse();
});

it('hands 0 to the author link filter for an identifier that is not numeric', function (): void {
    (new AuthorLinkRewriter(new WordPressHookRegistrar))->register();
    add_filter('portcullis/author_link', static fn (string $home, int $id): string => $home.'team/'.$id, 10, 2);

    expect(apply_filters('author_link', 'https://example.test/author/admin/', '12abc'))->toBe('https://example.test/team/0');
});

it('replaces a pre-registered hook entry that is not an array', function (): void {
    $result = runInSubprocess(
        <<<'PHP'
        echo count($GLOBALS['wp_filter']['muplugins_loaded'][0]);
        PHP,
        withFakes: false,
        prelude: "\$GLOBALS['wp_filter'] = ['muplugins_loaded' => 'not an array'];",
    );

    expect($result['stderr'])->toBe('')
        ->and($result['stdout'])->toBe('1');
});
