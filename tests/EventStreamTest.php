<?php

/*
 * EventStreamResponse in Slim routes, from the outside with raw sockets, once without and once with
 * phasync-ext. The wire format is swerve's ServerSentEvents; this is about what a PSR-15 handler
 * can ask for, and the headers that middleware adds on the way.
 */

/** GET $path as an EventSource does, and read the head. */
function sse_open(string $addr, string $path, array $headers = [], string $method = 'GET'): array
{
    $conn = native_connect($addr);
    $head = "$method $path HTTP/1.1\r\nHost: test\r\nAccept: text/event-stream\r\n";
    foreach ($headers as $name => $value) {
        $head .= "$name: $value\r\n";
    }
    \fwrite($conn, "$head\r\n");

    return [$conn, native_read_head($conn)];
}

/** Every chunk of the body up to the last chunk. */
function sse_chunks($conn): array
{
    $chunks = [];
    while ('' !== ($chunk = native_read_chunk($conn)) && null !== $chunk) {
        $chunks[] = $chunk;
    }

    return $chunks;
}

test('an event stream from a Slim route: the head, the middleware\'s and the route\'s headers, the events, and the end when the callback returns', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode);
    try {
        [$conn, $head] = sse_open($addr, '/sse/events');

        expect($head['status'])->toBe(200);
        expect($head['headers']['content-type'])->toBe('text/event-stream');
        expect($head['headers']['cache-control'])->toBe('no-cache');
        expect($head['headers']['x-accel-buffering'])->toBe('no');
        expect($head['headers']['transfer-encoding'])->toBe('chunked');
        expect($head['headers'])->not->toHaveKey('content-length');
        // Given to stream(), and added by the middleware, which cloned the response
        expect($head['headers']['x-own'])->toBe('given');
        expect($head['headers']['x-mw'])->toBe('seen');
        expect($head['headers']['access-control-allow-origin'])->toBe('*');
        expect($head['cookies'])->toBe(['a=1', 'b=2']);
        expect(sse_chunks($conn))->toBe(["event: greeting\nid: 1\ndata: one\n\n", "id: 2\ndata: two\ndata: lines\n\n", ": done\n\n"]);
        // The stream is over, and the connection serves another request
        \fwrite($conn, "GET /hello HTTP/1.1\r\nHost: test\r\n\r\n");
        expect(native_read_response($conn)['body'])->toBe('Hello');
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('Last-Event-ID is on the PSR request and on ServerSentEvents', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode);
    try {
        [$conn] = sse_open($addr, '/sse/last', ['Last-Event-ID' => '42']);
        expect(sse_chunks($conn))->toBe(["data: [\"42\",\"42\"]\n\n"]);

        [$conn] = sse_open($addr, '/sse/last');
        expect(sse_chunks($conn))->toBe(["data: [\"\",null]\n\n"]);
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('events published by an ordinary route reach every stream on both workers, and a client that leaves ends its callback', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode, 2);
    try {
        $clients = [];
        for ($i = 0; $i < 8; ++$i) {
            [$clients[], $head] = sse_open($addr, '/sse/news');
            expect($head['status'])->toBe(200);
        }
        [$total, $workers] = rt_live($addr, 'sse', 8, 2);
        expect($total)->toBe(8);
        expect($workers)->toBe(2);

        for ($n = 0; $n < 10; ++$n) {
            $request = "POST /publish/news HTTP/1.1\r\nHost: t\r\nContent-Length: " . \strlen("m$n") . "\r\nConnection: close\r\n\r\nm$n";
            expect(psr15_request($addr, $request)['body'])->toBe('published');
        }
        foreach ($clients as $i => $conn) {
            $got = [];
            while (\count($got) < 10) {
                $chunk = native_read_chunk($conn);
                if (!\str_starts_with($chunk, ':')) { // a keep-alive comment may come between
                    $got[] = $chunk;
                }
            }
            expect($got)->toBe(\array_map(fn ($n) => "event: news\ndata: m$n\n\n", \range(0, 9)), "client $i");
        }

        foreach ($clients as $conn) {
            \fclose($conn);
        }
        expect(rt_live($addr, 'sse', 0, 2)[0])->toBe(0);
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('HEAD gets the head and no body, the callback does not run, and the connection serves on', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode);
    try {
        $conn = native_connect($addr);
        \fwrite($conn, "HEAD /sse/head HTTP/1.1\r\nHost: t\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        $head = native_read_response($conn, true);

        expect($head['status'])->toBe(200);
        expect($head['headers']['content-type'])->toBe('text/event-stream');
        expect($head['headers']['x-mw'])->toBe('seen');
        expect($head['body'])->toBe('');
        expect(native_read_response($conn)['body'])->toBe('Hello');
        expect(\json_decode(psr15_get($addr, '/live/head')['body'], true)[1])->toBe(0);

        // A GET runs it
        [$conn] = sse_open($addr, '/sse/head');
        expect(sse_chunks($conn))->toBe(["data: x\n\n"]);
        expect(\json_decode(psr15_get($addr, '/live/head')['body'], true)[1])->toBe(1);
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('an exception in the callback is logged and aborts the stream after its first event', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode);
    try {
        [$conn, $head] = sse_open($addr, '/sse/fail');
        expect($head['status'])->toBe(200);
        expect(native_read_chunk($conn))->toBe("data: before\n\n");
        expect(native_read_chunk($conn))->toBeNull();   // no last chunk: the connection was aborted
        log_wait($log, '/the stream failed/');
        expect(psr15_get($addr, '/hello')['body'])->toBe('Hello');
    } finally {
        native_stop($process);
    }
})->with('modes');
