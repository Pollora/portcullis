<?php

declare(strict_types=1);

/*
 * The code that ends the request with `exit`, and the boot that has to run
 * before WordPress exists. None of it can be observed from the test process,
 * so each case runs in a process of its own (see runInSubprocess()).
 */

if (! function_exists('proc_open')) {
    it('needs proc_open')->skip('proc_open is disabled.');

    return;
}

const SUB_HOOKS = <<<'PHP'
$hooks = new class implements Pollora\Portcullis\Port\Out\HookRegistrarPort {
    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {}
    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {}
    public function removeAction(string $hook, callable|string $callback, int $priority = 10): void {}
    public function doAction(string $hook, mixed ...$args): void {}
    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $hook === 'portcullis/render_theme_404' ? ($GLOBALS['render_theme'] ?? false) : $value;
    }
};
PHP;

it('schedules itself on the pre-initialised hook array when WordPress is not loaded yet', function (): void {
    $result = runInSubprocess(<<<'PHP'
    echo json_encode($GLOBALS['wp_filter']['muplugins_loaded']);
    PHP, withFakes: false);

    expect($result['status'])->toBe(0)
        ->and(json_decode($result['stdout'], true))->toBe([[['function' => ['Pollora\Portcullis\Portcullis', 'boot'], 'accepted_args' => 0]]]);
});

it('replaces a hook array that is not an array rather than failing', function (): void {
    $result = runInSubprocess(<<<'PHP'
    echo json_encode($GLOBALS['wp_filter']['muplugins_loaded'][0][0]['accepted_args']);
    PHP, withFakes: false, prelude: "\$GLOBALS['wp_filter'] = 'garbage';");

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toBe('0');
});

it('answers a blocked request with a bare 404 document and ends the request', function (): void {
    $result = runInSubprocess(SUB_HOOKS.<<<'PHP'

    use Pollora\Portcullis\Adapter\Out\WordPress\ThemeNotFoundResponder;
    use Pollora\Portcullis\Tests\Support\FakeRequestContext;
    use Pollora\Portcullis\Tests\Support\Wp;

    register_shutdown_function(static function (): void {
        echo '|statuses='.json_encode(Wp::$statuses).'|nocache='.Wp::$noCacheHeaders;
    });

    (new ThemeNotFoundResponder(new FakeRequestContext(uri: '/wp-admin/'), $hooks))->respond();
    echo 'NOT REACHED';
    PHP);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('<h1>404</h1>')
        ->and($result['stdout'])->toContain('|statuses=[404]|nocache=')
        ->and($result['stdout'])->not->toContain('NOT REACHED');
});

it('answers a blocked request through the theme\'s 404 template, with a 404 status', function (): void {
    $result = runInSubprocess(SUB_HOOKS.<<<'PHP'

    use Pollora\Portcullis\Adapter\Out\WordPress\ThemeNotFoundResponder;
    use Pollora\Portcullis\Tests\Support\FakeRequestContext;
    use Pollora\Portcullis\Tests\Support\Wp;

    $dir = sys_get_temp_dir().'/portcullis-wp-'.getmypid();
    mkdir($dir.'/wp-includes', 0777, true);
    file_put_contents($dir.'/wp-includes/template-loader.php', "<?php echo 'THEME-404';");
    define('ABSPATH', $dir.'/');
    define('WPINC', 'wp-includes');
    function wp() { echo 'WP-RAN|'; }

    $GLOBALS['render_theme'] = true;
    $GLOBALS['wp_query'] = new WP_Query;

    register_shutdown_function(static function () use ($dir): void {
        echo '|statuses='.json_encode(Wp::$statuses).'|set404='.var_export($GLOBALS['wp_query']->set404, true).'|themes='.var_export(WP_USE_THEMES, true);
        unlink($dir.'/wp-includes/template-loader.php');
        rmdir($dir.'/wp-includes');
        rmdir($dir);
    });

    (new ThemeNotFoundResponder(new FakeRequestContext(uri: '/wp-admin/'), $hooks))->respond();
    PHP);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('WP-RAN|THEME-404')
        ->and($result['stdout'])->toContain('|statuses=[404]|set404=true|themes=true');
});

it('serves wp-login.php from the secret slug and ends the request', function (): void {
    $result = runInSubprocess(<<<'PHP'
    use Pollora\Portcullis\Adapter\Out\WordPress\WpLoginScreenRenderer;
    use Pollora\Portcullis\Domain\Model\LoginSlug;
    use Pollora\Portcullis\Tests\Support\FakeRequestContext;

    $dir = sys_get_temp_dir().'/portcullis-wp-'.getmypid();
    mkdir($dir);
    file_put_contents($dir.'/wp-login.php', "<?php echo 'LOGIN-SCREEN pagenow='.\$GLOBALS['pagenow'].' uri='.\$_SERVER['REQUEST_URI'].' action='.var_export(isset(\$action), true);");
    define('ABSPATH', $dir.'/');
    register_shutdown_function(static function () use ($dir): void {
        unlink($dir.'/wp-login.php');
        rmdir($dir);
    });

    $renderer = new WpLoginScreenRenderer(new FakeRequestContext(uri: '/acces-secret/?action=lostpassword'));
    $renderer->prepare(LoginSlug::fromString('acces-secret'));
    $renderer->render();
    echo 'NOT REACHED';
    PHP);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toBe('LOGIN-SCREEN pagenow=wp-login.php uri=/acces-secret?action=lostpassword action=false');
});

it('sends an admin alias to the dashboard and ends the request, instead of leaking the slug', function (): void {
    $result = runInSubprocess(<<<'PHP'
    use Pollora\Portcullis\Adapter\In\WordPress\StockAliasRouter;
    use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;
    use Pollora\Portcullis\Application\Service\ClassifyStockAlias;
    use Pollora\Portcullis\Tests\Support\FakeRequestContext;
    use Pollora\Portcullis\Tests\Support\Wp;

    function wp_safe_redirect($location, $status = 302, $x_redirect_by = 'WordPress') { echo 'redirect:'.$location; return true; }

    Wp::$is404 = true;
    $GLOBALS['wp_rewrite'] = new WP_Rewrite(true);

    (new StockAliasRouter(new ClassifyStockAlias, new FakeRequestContext(uri: '/admin'), new WordPressHookRegistrar))->route();
    echo 'NOT REACHED';
    PHP);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toBe('redirect:https://example.test/wp/wp-admin');
});

it('registers the command under both names inside WP-CLI', function (): void {
    $result = runInSubprocess(<<<'PHP'
    use Pollora\Portcullis\Adapter\In\WordPress\Cli\PortcullisCommand;
    use Pollora\Portcullis\Tests\Support\InMemorySlugProvider;

    define('WP_CLI', true);
    PortcullisCommand::register(new PortcullisCommand(new InMemorySlugProvider(null)));
    echo json_encode(array_keys(WP_CLI::$commands));
    PHP);

    expect($result['stdout'])->toBe('["portcullis","hidden-login"]');
});

it('never blocks WP-CLI, whatever script it runs as', function (): void {
    $result = runInSubprocess(<<<'PHP'
    use Pollora\Portcullis\Adapter\Out\WordPress\SuperglobalRequestContext;
    use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;

    define('WP_CLI', true);
    echo var_export((new SuperglobalRequestContext(new WordPressHookRegistrar))->isNonHttpContext(), true);
    PHP);

    expect($result['stdout'])->toBe('true');
});

it('never blocks a request that defines DOING_CRON', function (): void {
    $result = runInSubprocess(<<<'PHP'
    use Pollora\Portcullis\Adapter\Out\WordPress\SuperglobalRequestContext;
    use Pollora\Portcullis\Adapter\Out\WordPress\WordPressHookRegistrar;

    define('DOING_CRON', true);
    echo var_export((new SuperglobalRequestContext(new WordPressHookRegistrar))->isNonHttpContext(), true);
    PHP);

    expect($result['stdout'])->toBe('true');
});
