<?php

declare(strict_types=1);

/**
 * Helpers of the benchmark scripts: built-in web server per environment, requests, cache builds, statistics
 */

const APP_ROOT = __DIR__ . '/..';
const PORT     = 8090;

/**
 * php.ini overrides of every run: OPcache on, JIT off (PHP 8.5.11/8.5.12 tracing JIT bug), file_update_protection
 * off (files written a moment ago are cached at once; the AOP cache is written atomically), no Xdebug.
 * variables_order=EGPCS: the built-in server passes APP_ENV to Symfony through $_ENV (PHP-FPM uses $_SERVER)
 *
 * @return list<string>
 */
function baseIni(): array
{
    return [
        'opcache.enable=1',
        'opcache.enable_cli=1',
        'opcache.jit=disable',
        'opcache.file_update_protection=0',
        'opcache.memory_consumption=128',
        'opcache.max_accelerated_files=20000',
        'opcache.interned_strings_buffer=16',
        'memory_limit=512M',
        'variables_order=EGPCS',
    ];
}

/**
 * @param list<string>          $ini
 * @param array<string, string> $env
 * @return resource
 */
function startServer(string $appEnv, array $ini = [], array $env = [])
{
    $command = [PHP_BINARY];
    foreach (array_merge(baseIni(), $ini) as $setting) {
        $command[] = '-d';
        $command[] = $setting;
    }
    array_push($command, '-S', '127.0.0.1:' . PORT, '-t', APP_ROOT . '/public', APP_ROOT . '/public/index.php');

    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        APP_ROOT,
        ['APP_ENV' => $appEnv] + $env + getenv(),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Can not start the web server');
    }
    for ($i = 0; $i < 300; $i++) {
        $socket = @fsockopen('127.0.0.1', PORT, $errno, $errstr, 0.05);
        if ($socket !== false) {
            fclose($socket);

            return $process;
        }
        usleep(10_000);
    }
    throw new RuntimeException('The web server did not start');
}

/** @param resource $process */
function stopServer($process): void
{
    proc_terminate($process);
    proc_close($process);
    for ($i = 0; $i < 200; $i++) {
        $socket = @fsockopen('127.0.0.1', PORT, $errno, $errstr, 0.02);
        if ($socket === false) {
            return;
        }
        fclose($socket);
        usleep(10_000);
    }
}

/**
 * @return array{client_ms: float, app: array<string, mixed>}
 */
function request(string $path = '/'): array
{
    $start = hrtime(true);
    $body  = file_get_contents('http://127.0.0.1:' . PORT . $path);
    $time  = (hrtime(true) - $start) / 1e6;
    $data  = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($data)) {
        throw new RuntimeException('Bad response: ' . substr((string) $body, 0, 2000));
    }

    return ['client_ms' => $time, 'app' => $data];
}

function cacheDir(string $appEnv): string
{
    return APP_ROOT . '/var/cache/' . $appEnv;
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) && !is_link($path) ? removeTree($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * @param list<string> $arguments
 * @param list<string> $ini
 */
function console(string $appEnv, array $arguments, array $ini = []): string
{
    $command = 'APP_ENV=' . escapeshellarg($appEnv) . ' ' . escapeshellarg(PHP_BINARY);
    foreach (array_merge(['opcache.enable_cli=1', 'opcache.jit=disable', 'opcache.file_update_protection=0', 'memory_limit=512M'], $ini) as $setting) {
        $command .= ' -d ' . escapeshellarg($setting);
    }
    $command .= ' ' . escapeshellarg(APP_ROOT . '/bin/console') . ' ' . implode(' ', array_map(escapeshellarg(...), $arguments)) . ' 2>&1';
    exec($command, $output, $code);
    if ($code !== 0) {
        throw new RuntimeException("bin/console failed:\n" . implode("\n", $output));
    }

    return implode("\n", $output);
}

/**
 * Builds the cache of an environment from scratch (container, routes, AOP cache, preload scripts): the deployment
 * step. Returns the wall time in ms
 */
function buildCache(string $appEnv): float
{
    removeTree(cacheDir($appEnv));
    $start = hrtime(true);
    console($appEnv, ['cache:clear']);

    return (hrtime(true) - $start) / 1e6;
}

/** @param list<float> $values */
function median(array $values): float
{
    sort($values);
    $count = count($values);
    if ($count === 0) {
        return NAN;
    }
    $middle = intdiv($count, 2);

    return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

/** @param list<float> $values */
function percentile(array $values, float $p): float
{
    sort($values);

    return $values[(int) min(count($values) - 1, floor($p * count($values)))];
}
