<?php

/*
 * A Slim 4 application behind `vendor/bin/swerve`: what the adapter makes of a real PSR-15 framework.
 */

beforeEach(function () {
    [$this->process, $this->addr, $this->log] = psr15_start('slim.php', php: ['-d', 'memory_limit=32M']);
});

test('a route answers with the body its handler wrote', function () {
    $response = psr15_get($this->addr, '/hello');

    expect($response['status'])->toBe(200);
    expect($response['body'])->toBe('Hello');
    expect($response['headers']['content-length'])->toBe('5');
});

test('route parameters reach the handler', function () {
    $response = psr15_get($this->addr, '/users/42/posts/hello-world');

    expect($response['status'])->toBe(200);
    expect($response['headers']['content-type'])->toBe('application/json');
    expect(\json_decode($response['body'], true))->toBe(['id' => '42', 'slug' => 'hello-world']);
});

test('a JSON request is parsed by Slim and answered in JSON', function () {
    $payload  = \json_encode(['name' => 'Frode', 'tags' => ['a', 'b']]);
    $response = psr15_request($this->addr, "POST /json HTTP/1.1\r\nHost: t\r\nContent-Type: application/json\r\nContent-Length: " . \strlen($payload) . "\r\nConnection: close\r\n\r\n$payload");

    expect($response['status'])->toBe(201);
    expect(\json_decode($response['body'], true))->toBe(['received' => ['name' => 'Frode', 'tags' => ['a', 'b']], 'type' => 'application/json']);
});

test('request headers are case insensitive, cookies are parsed, and response headers and cookies are sent', function () {
    $response = psr15_get($this->addr, '/headers', ['X-Test' => 'one', 'Cookie' => 'sid=abc; theme=dark%20blue']);

    expect(\json_decode($response['body'], true))->toBe(['x' => ['one'], 'cookies' => ['sid' => 'abc', 'theme' => 'dark blue'], 'cased' => 'one']);
    expect($response['headers']['x-reply'])->toBe('yes');
    expect($response['cookies'])->toBe(['a=1; Path=/', 'b=2; HttpOnly']);
});

test('Slim answers 404 and 405 itself', function () {
    $missing = psr15_get($this->addr, '/nowhere');
    $method  = psr15_request($this->addr, "DELETE /hello HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");

    expect($missing['status'])->toBe(404);
    expect($method['status'])->toBe(405);
    expect($method['headers']['allow'])->toContain('GET');
});

test('middleware runs in Slim\'s order on the way in and out', function () {
    $response = psr15_get($this->addr, '/order');

    // Slim runs the last added middleware first
    expect(\json_decode($response['body'], true))->toBe(['B', 'A']);
    expect($response['headers']['x-out'])->toBe('A,B');
});

test('a response without a body is sent without one', function () {
    $response = psr15_get($this->addr, '/empty');

    expect($response['status'])->toBe(204);
    expect($response['body'])->toBe('');
});

test('a request body streams into a route as it arrives', function () {
    $conn  = native_connect($this->addr);
    $start = \microtime(true);
    \fwrite($conn, "POST /first/5 HTTP/1.1\r\nHost: t\r\nContent-Length: 1000000\r\n\r\nhello");

    expect(native_read_response($conn)['body'])->toBe('hello');
    expect(\microtime(true) - $start)->toBeLessThan(1.0);
});

test('a chunked request body echoed by Slim streams in both directions at once', function () {
    $conn = native_connect($this->addr);
    \fwrite($conn, "POST /echo-stream HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n");
    native_read_head($conn);
    expect(native_read_chunk($conn))->toBe('hello');

    \fwrite($conn, "6\r\n world\r\n0\r\n\r\n");
    expect(native_read_chunk($conn))->toBe(' world');
    expect(native_read_chunk($conn))->toBe('');
});

test('a large chunked request body sent in small writes reaches the route whole', function () {
    $body = \random_bytes(3000000);
    $conn = native_connect($this->addr);
    \fwrite($conn, "POST /count HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n");
    foreach (\str_split($body, 7000) as $chunk) {
        \fwrite($conn, \dechex(\strlen($chunk)) . "\r\n$chunk\r\n");
    }
    \fwrite($conn, "0\r\n\r\n");

    expect(native_read_response($conn)['body'])->toBe('3000000:' . \md5($body));
});

test('a request body larger than the worker\'s memory limit streams through', function () {
    [$process, $addr] = psr15_start('slim.php', ['--max-body=0'], workers: 1, php: ['-d', 'memory_limit=32M']);
    $size             = 48 << 20;
    $conn             = native_connect($addr);
    \fwrite($conn, "POST /count HTTP/1.1\r\nHost: t\r\nContent-Length: $size\r\n\r\n");
    $hash = \hash_init('md5');
    for ($i = 0; $i * GeneratedStream::CHUNK < $size; ++$i) {
        $chunk = GeneratedStream::chunk($i, $size);
        \hash_update($hash, $chunk);
        \fwrite($conn, $chunk);
    }

    expect(native_read_response($conn)['body'])->toBe("$size:" . \hash_final($hash));
    native_stop($process);
});

test('a response body larger than the worker\'s memory limit streams out, chunked when its size is unknown', function (string $path, bool $sized) {
    $mb   = 48;
    $size = $mb << 20;
    $conn = native_connect($this->addr);
    \fwrite($conn, "GET $path/$mb HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
    $head = native_read_head($conn);

    expect($head['status'])->toBe(200);
    if ($sized) {
        expect($head['headers']['content-length'])->toBe((string) $size);
    } else {
        expect($head['headers'])->not->toHaveKey('content-length');
        expect($head['headers']['transfer-encoding'])->toBe('chunked');
    }
    $hash  = \hash_init('md5');
    $total = 0;
    while ($total < $size) {
        $data = $sized ? \fread($conn, 65536) : native_read_chunk($conn);
        expect($data)->not->toBeEmpty();
        $total += \strlen($data);
        \hash_update($hash, $data);
    }
    $expected = \hash_init('md5');
    for ($i = 0; $i * GeneratedStream::CHUNK < $size; ++$i) {
        \hash_update($expected, GeneratedStream::chunk($i, $size));
    }

    expect($total)->toBe($size);
    expect(\hash_final($hash))->toBe(\hash_final($expected));
})->with([
    'unsized' => ['/big', false],
    'sized'   => ['/big-sized', true],
]);

test('Expect: 100-continue is answered when the route reads the body', function () {
    $conn = native_connect($this->addr);
    \fwrite($conn, "POST /count HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\nExpect: 100-continue\r\n\r\n");

    expect(native_read_response($conn)['status'])->toBe(100);
    \fwrite($conn, 'hello');
    expect(native_read_response($conn)['body'])->toBe('5:' . \md5('hello'));
});

test('a HEAD response has no body, and keeps the connection usable', function () {
    $conn = native_connect($this->addr);
    \fwrite($conn, "HEAD /hello HTTP/1.1\r\nHost: t\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    $head = native_read_response($conn, true);

    $get  = native_read_response($conn);

    expect($head['status'])->toBe(200);
    expect($head['body'])->toBe('');
    expect($head['headers'])->not->toHaveKey('content-length'); // Slim answers HEAD with an empty body
    expect($get['body'])->toBe('Hello');
});

test('several requests on one connection are each answered in turn', function () {
    $conn = native_connect($this->addr);
    $seen = [];
    foreach (['/hello', '/users/1/posts/a', '/nowhere', '/hello'] as $path) {
        \fwrite($conn, "GET $path HTTP/1.1\r\nHost: t\r\n\r\n");
        $response = native_read_response($conn);
        $seen[]   = [$response['status'], $response['complete']];
    }

    expect($seen)->toBe([[200, true], [200, true], [404, true], [200, true]]);
});

test('a worker serves other requests while a handler waits', function () {
    [$process, $addr] = psr15_start('slim.php', workers: 1);
    $start            = \microtime(true);
    $slow             = [];
    foreach ([1, 2] as $_) {
        $conn = native_connect($addr);
        \fwrite($conn, "GET /slow HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        $slow[] = $conn;
    }
    \usleep(200000);
    $quick   = psr15_get($addr, '/hello');
    $quickAt = \microtime(true) - $start;
    $bodies  = \array_map(fn ($c) => native_read_response($c)['body'], $slow);
    $total   = \microtime(true) - $start;

    expect($quick['body'])->toBe('Hello');
    expect($quickAt)->toBeLessThan(0.8);
    expect($bodies)->toBe(['slow', 'slow']);
    expect($total)->toBeLessThan(1.8); // one second of waiting, shared
    native_stop($process);
});

test('the request as the application sees it', function () {
    $response = psr15_request($this->addr, "GET /info?a=1&b[]=2&b[]=3 HTTP/1.1\r\nHost: example.test:8080\r\nConnection: close\r\n\r\n");
    $info     = \json_decode($response['body'], true);

    expect($info['method'])->toBe('GET');
    expect($info['uri'])->toBe('http://example.test:8080/info?a=1&b[]=2&b[]=3');
    expect($info['target'])->toBe('/info?a=1&b[]=2&b[]=3');
    expect($info['query'])->toBe(['a' => '1', 'b' => ['2', '3']]);
    expect($info['version'])->toBe('1.1');
    expect($info['remote'])->toBe('127.0.0.1');
    expect($info['https'])->toBeNull();
    expect($info['port'])->toBe((int) \explode(':', $this->addr)[1]);
});
