<?php

/*
 * The tests run the adapter the way users do: a real `vendor/bin/swerve` serving an application
 * directory, with raw sockets as the clients. Every swerve runs in a process group of its own,
 * which is killed after the test. To run the suite with phasync-ext, put it in the ini files
 * PHP scans (PHP_INI_SCAN_DIR=:/dir/with/ext.ini): the swerve processes inherit it.
 */

require_once __DIR__ . '/Fixtures/streams.php';

uses()->afterEach(function () {
    foreach ($GLOBALS['psr15_groups'] ?? [] as $pgid) {
        @\posix_kill(-$pgid, \SIGKILL);
    }
    foreach ($GLOBALS['psr15_temp'] ?? [] as $path) {
        \exec('rm -rf ' . \escapeshellarg($path));
    }
    $GLOBALS['psr15_groups'] = $GLOBALS['psr15_temp'] = [];
})->in(__DIR__);

/** A new temporary file (or with $dir, directory), removed after the test. */
function temp_path(bool $dir = false): string
{
    $path = \tempnam(\sys_get_temp_dir(), 'swerve-psr15-');
    if ($dir) {
        \unlink($path);
        \mkdir($path);
    }
    $GLOBALS['psr15_temp'][] = $path;

    return $path;
}

function free_address(): string
{
    $probe = \stream_socket_server('tcp://127.0.0.1:0');
    $addr  = \stream_socket_get_name($probe, false);
    \fclose($probe);

    return $addr;
}

/**
 * An application directory the way Composer leaves one: a vendor/composer/installed.json that lists
 * this package (the root package of this checkout is not in the real one), and a swerve.psr15.php
 * that returns what the fixture file in tests/Fixtures returns, or $code when it has no fixture.
 */
function psr15_app(?string $fixture, ?string $code = null): string
{
    $dir = temp_path(dir: true);
    \mkdir("$dir/vendor/composer", 0777, true);
    $package = \json_decode(\file_get_contents(__DIR__ . '/../composer.json'), true);
    \file_put_contents("$dir/vendor/composer/installed.json", \json_encode(['packages' => [['name' => $package['name'], 'extra' => $package['extra']]]]));
    if (null !== $fixture) {
        $code = 'return require ' . \var_export(__DIR__ . "/Fixtures/$fixture", true) . ';';
    }
    if (null !== $code) {
        \file_put_contents("$dir/swerve.psr15.php", "<?php\n$code\n");
    }

    return $dir;
}

/**
 * Start vendor/bin/swerve in $dir (an application directory) in a process group of its own.
 *
 * @param string[]              $args swerve's arguments
 * @param string[]              $php  PHP's own, such as ['-d', 'memory_limit=32M']
 * @param array<string, string> $env  more environment variables
 *
 * @return array{0: resource, 1: string, 2: string} the process, its address and its log file
 */
function psr15_spawn(string $dir, array $args = [], array $php = [], array $env = []): array
{
    $addr    = free_address();
    $log     = temp_path();
    $process = \proc_open(
        ['setsid', \PHP_BINARY, ...$php, __DIR__ . '/../vendor/bin/swerve', "--http=$addr", "--log=$log", '--grace=2', '--watchdog=10', ...$args],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $dir,
        $env + \getenv(),
    );
    $GLOBALS['psr15_groups'][] = \proc_get_status($process)['pid'];

    return [$process, $addr, $log];
}

/**
 * Serve a fixture application and wait until it answers.
 *
 * @param string[] $args swerve's arguments
 * @param string[] $php  PHP's own
 *
 * @return array{0: resource, 1: string, 2: string} the process, its address and its log file
 */
function psr15_start(string $fixture = 'slim.php', array $args = [], int $workers = 2, array $php = []): array
{
    $started                = psr15_spawn(psr15_app($fixture), ["--workers=$workers", ...$args], $php);
    [$process, $addr, $log] = $started;
    $deadline               = \microtime(true) + 15;
    while (!psr15_answers($addr)) {
        if (\microtime(true) > $deadline || !\proc_get_status($process)['running']) {
            throw new RuntimeException("swerve did not start serving on $addr:\n" . \file_get_contents($log));
        }
        \usleep(50000);
    }

    return $started;
}

/** Whether something speaking HTTP answers on $addr. */
function psr15_answers(string $addr): bool
{
    \set_error_handler(static fn (): bool => true);
    try {
        $conn = \stream_socket_client("tcp://$addr", $errno, $errstr, 1);
    } finally {
        \restore_error_handler();
    }
    if (false === $conn) {
        return false;
    }
    \stream_set_timeout($conn, 2);
    \fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
    $head = \fgets($conn);
    \fclose($conn);

    return \is_string($head) && \str_starts_with($head, 'HTTP/1.1 ');
}

/** One request on a connection of its own, and its response. */
function psr15_request(string $addr, string $request): array
{
    $conn = native_connect($addr);
    \fwrite($conn, $request);
    $response = native_read_response($conn, \str_starts_with($request, 'HEAD '));
    \fclose($conn);

    return $response;
}

/** A GET on a connection of its own, with the headers given, and its response. */
function psr15_get(string $addr, string $path, array $headers = []): array
{
    $lines = '';
    foreach ($headers as $name => $value) {
        $lines .= "$name: $value\r\n";
    }

    return psr15_request($addr, "GET $path HTTP/1.1\r\nHost: t\r\n{$lines}Connection: close\r\n\r\n");
}

/** Stop swerve with SIGINT, as Ctrl+C does, and wait for it; past the timeout, kill its group. */
function native_stop($process, float $timeout = 10): float
{
    $start = \microtime(true);
    $pid   = \proc_get_status($process)['pid'];
    \posix_kill($pid, \SIGINT);
    while (\proc_get_status($process)['running']) {
        if (\microtime(true) - $start > $timeout) {
            \posix_kill(-$pid, \SIGKILL);
            break;
        }
        \usleep(20000);
    }
    \proc_close($process);

    return \microtime(true) - $start;
}
function native_connect(string $addr)
{
    $conn = \stream_socket_client("tcp://$addr", $errno, $errstr, 5);
    \stream_set_timeout($conn, 5);

    return $conn;
}

function native_read_head($conn): ?array
{
    $head = '';
    while (!\str_contains($head, "\r\n\r\n")) {
        $line = \fgets($conn);
        if (false === $line) {
            return null;
        }
        $head .= $line;
    }
    $lines   = \explode("\r\n", \rtrim($head));
    $status  = (int) \explode(' ', \array_shift($lines))[1];
    $headers = [];
    $cookies = [];
    foreach ($lines as $line) {
        [$name, $value]                     = \explode(':', $line, 2);
        $headers[\strtolower(\trim($name))] = \trim($value);
        if ('set-cookie' === \strtolower(\trim($name))) {
            $cookies[] = \trim($value);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'cookies' => $cookies];
}

function native_read_chunk($conn): ?string
{
    $line = \fgets($conn);
    if (false === $line) {
        return null;
    }
    $size = \hexdec(\trim($line));
    if (0 === $size) {
        return false === \fgets($conn) ? null : '';
    }
    $chunk = '';
    while (\strlen($chunk) < $size) {
        $data = \fread($conn, $size - \strlen($chunk));
        if (false === $data || ('' === $data && \feof($conn))) {
            return null;
        }
        $chunk .= $data;
    }
    \fgets($conn); // CRLF after the chunk

    return $chunk;
}

function native_read_response($conn, bool $head = false): ?array
{
    $response = native_read_head($conn);
    if (null === $response) {
        return null;
    }
    $headers  = $response['headers'];
    $body     = '';
    $complete = true;
    if ($head || $response['status'] < 200 || 204 === $response['status'] || 304 === $response['status']) {
        // no body
    } elseif (isset($headers['content-length'])) {
        $length = (int) $headers['content-length'];
        while (\strlen($body) < $length) {
            $data = \fread($conn, $length - \strlen($body));
            if (false === $data || ('' === $data && \feof($conn))) {
                $complete = false;
                break;
            }
            $body .= $data;
        }
    } elseif ('chunked' === \strtolower($headers['transfer-encoding'] ?? '')) {
        while (true) {
            $chunk = native_read_chunk($conn);
            if (null === $chunk) {
                $complete = false;
                break;
            }
            if ('' === $chunk) {
                break;
            }
            $body .= $chunk;
        }
    } else {
        $body = \stream_get_contents($conn);
    }

    return $response + ['body' => $body, 'complete' => $complete];
}

function swerve_wait($process, float $timeout): array
{
    $start = \microtime(true);
    while (($status = \proc_get_status($process))['running']) {
        if (\microtime(true) - $start > $timeout) {
            throw new RuntimeException("swerve did not exit within $timeout s");
        }
        \usleep(10000);
    }
    \proc_close($process);

    return [$status['exitcode'], \microtime(true) - $start];
}

function log_wait(string $log, string $regex, float $timeout = 5): array
{
    $deadline = \microtime(true) + $timeout;
    while (!\preg_match_all($regex, \is_file($log) ? \file_get_contents($log) : '', $matches, \PREG_SET_ORDER)) {
        if (\microtime(true) > $deadline) {
            throw new RuntimeException("No $regex in the log within $timeout s:\n" . \file_get_contents($log));
        }
        \usleep(50000);
    }

    return $matches;
}
