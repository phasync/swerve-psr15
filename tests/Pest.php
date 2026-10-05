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

/*
 * Helpers of the WebSocket and Server-Sent Events tests (tests/Fixtures/realtime.php), copied from
 * swerve's own tests: a WebSocket client (RFC 6455), minimal and written from the specification.
 */

dataset('modes', ['plain' => [[]], 'ext' => [['--ext']]]);

/**
 * Serve the realtime fixture in the mode of the dataset ([] or ['--ext']), and check that it is the mode.
 *
 * @param string[] $mode
 *
 * @return array{0: resource, 1: string, 2: string} the process, its address and its log file
 */
function rt_start(array $mode, int $workers = 1): array
{
    $started = psr15_start('realtime.php', $mode, $workers);
    expect(psr15_get($started[1], '/ext')['body'])->toBe($mode ? '1' : '0');

    return $started;
}

/** Nothing in the log that a clean run would not have: no error, no PHP warning. */
function rt_clean(string $log): void
{
    $text = (string) \file_get_contents($log);
    expect(\preg_match('/(ERROR|CRITICAL|WARNING|Unhandled|failed|Warning:|Notice:|Deprecated:|Fatal error)/', $text))->toBe(0, $text);
}

/** Exactly $length bytes from $conn, or null when the connection ended before the first byte. */
function read_exactly($conn, int $length): ?string
{
    $data = '';
    while (\strlen($data) < $length) {
        $chunk = \fread($conn, $length - \strlen($data));
        if (false === $chunk || '' === $chunk) {
            if (\feof($conn) || \stream_get_meta_data($conn)['timed_out']) {
                return '' === $data ? null : throw new RuntimeException('Connection ended inside a read');
            }
            continue;
        }
        $data .= $chunk;
    }

    return $data;
}

/**
 * Send a handshake request with $headers added to the usual ones (a null value removes one), and read the response head.
 *
 * @param array<string, string|null> $headers
 *
 * @return array{0: resource, 1: array{status: int, headers: array<string, string>, cookies: string[]}}
 */
function ws_handshake(string $addr, string $path, array $headers = [], string $method = 'GET'): array
{
    $headers += [
        'Host'                  => 'test',
        'Upgrade'               => 'websocket',
        'Connection'            => 'Upgrade',
        'Sec-WebSocket-Key'     => \base64_encode(\random_bytes(16)),
        'Sec-WebSocket-Version' => '13',
    ];
    $conn = native_connect($addr);
    $head = "$method $path HTTP/1.1\r\n";
    foreach (\array_filter($headers, static fn ($v) => null !== $v) as $name => $value) {
        $head .= "$name: $value\r\n";
    }
    \fwrite($conn, "$head\r\n");

    return [$conn, native_read_head($conn)];
}

/**
 * Open a WebSocket: the handshake, checking the 101 and its Sec-WebSocket-Accept.
 *
 * @param array<string, string|null> $headers
 *
 * @return resource the blocking connection, with a 5 s timeout
 */
function ws_connect(string $addr, string $path, array $headers = [])
{
    $key             = \base64_encode(\random_bytes(16));
    [$conn, $head]   = ws_handshake($addr, $path, $headers + ['Sec-WebSocket-Key' => $key]);
    expect($head['status'] ?? null)->toBe(101);
    expect($head['headers']['sec-websocket-accept'] ?? null)->toBe(\base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)));

    return $conn;
}

/** The bytes of a frame a client sends: masked, as the protocol requires. */
function client_frame(int $opcode, string $payload, bool $fin = true): string
{
    $n    = \strlen($payload);
    $mask = \random_bytes(4);
    $head = \chr(($fin ? 0x80 : 0) | $opcode) . match (true) {
        $n < 126   => \chr(0x80 | $n),
        $n < 65536 => \chr(0x80 | 126) . \pack('n', $n),
        default    => \chr(0x80 | 127) . \pack('J', $n),
    };

    return $head . $mask . ($payload ^ \substr(\str_repeat($mask, \intdiv($n, 4) + 1), 0, $n));
}

/** The bytes of a frame the server sends: FIN, the opcode and the payload, unmasked. */
function server_frame(int $opcode, string $payload): string
{
    $n   = \strlen($payload);
    $len = $n < 126 ? \chr($n) : ($n < 65536 ? \chr(126) . \pack('n', $n) : \chr(127) . \pack('J', $n));

    return \chr(0x80 | $opcode) . $len . $payload;
}

/** Send one frame, masked as a client must. */
function ws_send($conn, int $opcode, string $payload, bool $fin = true): void
{
    \fwrite($conn, client_frame($opcode, $payload, $fin));
}

/**
 * The next frame from the server: [opcode, payload], or null when the connection ended.
 *
 * @return array{0: int, 1: string}|null
 */
function ws_read($conn): ?array
{
    $head = read_exactly($conn, 2);
    if (null === $head) {
        return null;
    }
    $length = \ord($head[1]) & 0x7F;
    if (126 === $length) {
        $length = \unpack('n', read_exactly($conn, 2))[1];
    } elseif (127 === $length) {
        $length = \unpack('J', read_exactly($conn, 8))[1];
    }

    return [\ord($head[0]) & 0x0F, $length > 0 ? read_exactly($conn, $length) : ''];
}

/** The server ended the connection cleanly: a FIN, not a timeout. */
function ws_end($conn): bool
{
    $data = @\fread($conn, 1);

    return '' === $data && \feof($conn) && !\stream_get_meta_data($conn)['timed_out'];
}

/** The server closes with $code and then ends the connection. */
function ws_expect_close($conn, int $code): void
{
    expect(ws_read($conn))->toBe([8, \pack('n', $code)]);
    expect(ws_end($conn))->toBeTrue();
}

/**
 * Wait until /live/$kind says the callbacks of that kind running now are $n, summed over the
 * workers; the sum it ended with, and how many workers had one.
 *
 * @return array{0: int, 1: int}
 */
function rt_live(string $addr, string $kind, int $n, int $workers = 1): array
{
    $deadline = \microtime(true) + 8;
    do {
        $seen = [];
        for ($i = 0; $i < 40 * $workers && \count($seen) < $workers; ++$i) {
            [$pid, $count] = \json_decode(psr15_get($addr, "/live/$kind")['body'], true);
            $seen[$pid]    = $count;
        }
        if (\array_sum($seen) === $n) {
            break;
        }
        \usleep(50000);
    } while (\microtime(true) < $deadline);

    return [\array_sum($seen), \count(\array_filter($seen))];
}
