<?php

declare(strict_types=1);

/**
 * Runs a PHP snippet in a process of its own and returns what it printed.
 *
 * For the code that ends the request with `exit` or that must run before
 * WordPress' functions exist: neither can be observed from inside the test
 * process. `$prelude` runs before the autoloader; the snippet starts after the Composer autoloader and the WordPress
 * fakes are required; `$root` is the package root.
 *
 * @return array{stdout: string, stderr: string, status: int}
 */
function runInSubprocess(string $snippet, bool $withFakes = true, string $prelude = ''): array
{
    $root = dirname(__DIR__, 2);
    $script = tempnam(sys_get_temp_dir(), 'portcullis-sub').'.php';

    file_put_contents($script, "<?php\n".
        "declare(strict_types=1);\n".
        '$root = '.var_export($root, true).";\n".
        $prelude."\n".
        "require \$root.'/vendor/autoload.php';\n".
        ($withFakes ? "require \$root.'/tests/Support/wordpress-fakes.php';\nrequire \$root.'/tests/Support/wp-cli-fakes.php';\n" : '').
        $snippet."\n");

    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'memory_limit=512M', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start a PHP subprocess.');
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    unlink($script);

    return ['stdout' => $stdout, 'stderr' => $stderr, 'status' => $status];
}
