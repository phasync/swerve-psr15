<?php

/*
 * WebSocketResponse in Slim routes, from the outside with raw sockets, once without and once with
 * phasync-ext: nothing may differ. The frames and close codes are swerve's and tested there; this
 * is about what a PSR-15 handler can ask for.
 *
 * Nothing is slept for: a test waits for the condition it needs, with a generous deadline.
 */

test('text and binary are echoed through a Slim route, several in a row, and "bye" ends the callback with 1000', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode);
    try {
        $conn = ws_connect($addr, '/ws/echo');
        ws_send($conn, 1, 'hello');
        expect(ws_read($conn))->toBe([1, 'hello']);
        ws_send($conn, 2, "\x00\xFF");
        expect(ws_read($conn))->toBe([2, "\x00\xFF"]);
        for ($i = 0; $i < 50; ++$i) {
            ws_send($conn, 1, "message $i");
        }
        for ($i = 0; $i < 50; ++$i) {
            expect(ws_read($conn))->toBe([1, "message $i"]);
        }
        ws_send($conn, 1, \str_repeat('x', 100_000));
        expect(ws_read($conn))->toBe([1, \str_repeat('x', 100_000)]);
        ws_send($conn, 1, 'bye');
        ws_expect_close($conn, 1000);
        expect(rt_live($addr, 'ws', 0)[0])->toBe(0);
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('the middleware that clones the response does not stop the handshake or the callback, and its headers are not on the 101', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode);
    try {
        // The fixture's middleware adds headers to every response, with a clone
        expect(psr15_get($addr, '/hello')['headers']['x-mw'])->toBe('seen');

        [$conn, $head] = ws_handshake($addr, '/ws/echo');
        expect($head['status'])->toBe(101);
        expect($head['headers'])->not->toHaveKeys(['x-mw', 'access-control-allow-origin']);
        ws_send($conn, 1, 'still works');
        expect(ws_read($conn))->toBe([1, 'still works']);
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('a GET that is no handshake is answered 426 by swerve, without the middleware\'s headers, and the connection serves on', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode);
    try {
        $conn = native_connect($addr);
        \fwrite($conn, "GET /ws/echo HTTP/1.1\r\nHost: t\r\n\r\n");
        $response = native_read_response($conn);

        expect($response['status'])->toBe(426);
        expect($response['headers']['upgrade'])->toBe('websocket');
        expect($response['headers'])->not->toHaveKey('x-mw');
        \fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['body'])->toBe('Hello');

        // A handshake that is not valid is 400, naming the version
        [$bad, $head] = ws_handshake($addr, '/ws/echo', ['Sec-WebSocket-Version' => '8']);
        expect($head['status'])->toBe(400);
        expect($head['headers']['sec-websocket-version'])->toBe('13');
        expect(rt_live($addr, 'ws', 0)[0])->toBe(0);
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('the origin allow-list: another origin is 403, an allowed one (in any case) and none at all are let in', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode);
    try {
        [, $head] = ws_handshake($addr, '/ws/origin', ['Origin' => 'https://evil.example']);
        expect($head['status'])->toBe(403);

        $conn = ws_connect($addr, '/ws/origin', ['Origin' => 'HTTPS://Good.example']);
        expect(ws_read($conn))->toBe([1, 'welcome']);

        $conn = ws_connect($addr, '/ws/origin');
        expect(ws_read($conn))->toBe([1, 'welcome']);
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('subprotocols: the first of the server\'s list that the client offered is chosen, none in common opens without one', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode);
    try {
        [$conn, $head] = ws_handshake($addr, '/ws/protocol', ['Sec-WebSocket-Protocol' => 'v1.chat, other, v2.chat']);
        expect($head['status'])->toBe(101);
        expect($head['headers']['sec-websocket-protocol'])->toBe('v2.chat');
        expect(ws_read($conn))->toBe([1, '"v2.chat"']);

        [$conn, $head] = ws_handshake($addr, '/ws/protocol', ['Sec-WebSocket-Protocol' => 'other']);
        expect($head['status'])->toBe(101);
        expect($head['headers'])->not->toHaveKey('sec-websocket-protocol');
        expect(ws_read($conn))->toBe([1, 'null']);
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('maxMessage reaches swerve: a larger message closes with 1009', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode);
    try {
        $conn = ws_connect($addr, '/ws/small');
        ws_send($conn, 1, '0123456789');
        expect(ws_read($conn))->toBe([1, '0123456789']);
        ws_send($conn, 1, '0123456789a');
        ws_expect_close($conn, 1009);
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('server push: a socket forwards what an ordinary Slim route publishes, in order, to every client on both workers', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode, 2);
    try {
        $clients = [];
        for ($i = 0; $i < 12; ++$i) {
            $clients[] = ws_connect($addr, '/ws/news');
        }
        foreach ($clients as $conn) {
            expect(ws_read($conn))->toBe([1, 'subscribed']);
        }
        [$total, $workers] = rt_live($addr, 'ws', 12, 2);
        expect($total)->toBe(12);
        expect($workers)->toBe(2);

        for ($n = 0; $n < 20; ++$n) {
            $request = "POST /publish/news HTTP/1.1\r\nHost: t\r\nContent-Length: " . \strlen("m$n") . "\r\nConnection: close\r\n\r\nm$n";
            expect(psr15_request($addr, $request)['body'])->toBe('published');
        }
        foreach ($clients as $i => $conn) {
            $got = [];
            for ($n = 0; $n < 20; ++$n) {
                $got[] = ws_read($conn);
            }
            expect($got)->toBe(\array_map(fn ($n) => [1, "m$n"], \range(0, 19)), "client $i");
        }

        // Clients leave: half without a word, half with a close frame; every callback ends
        foreach ($clients as $i => $conn) {
            if ($i % 2) {
                ws_send($conn, 8, \pack('n', 1000));
                ws_expect_close($conn, 1000);
            }
            \fclose($conn);
        }
        expect(rt_live($addr, 'ws', 0, 2)[0])->toBe(0);
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('identity: the user read from the request before the response was returned is the callback\'s, per socket', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode, 2);
    try {
        $alice = ws_connect($addr, '/ws/me', ['Cookie' => 'user=alice']);
        $bob   = ws_connect($addr, '/ws/me', ['X-User' => 'bob']);
        for ($i = 0; $i < 3; ++$i) {
            ws_send($alice, 1, "a$i");
            ws_send($bob, 1, "b$i");
        }
        for ($i = 0; $i < 3; ++$i) {
            expect(ws_read($alice))->toBe([1, "alice: a$i"]);
            expect(ws_read($bob))->toBe([1, "bob: b$i"]);
        }
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('200 open sockets: ordinary Slim requests are answered promptly meanwhile', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode);
    try {
        $clients = [];
        for ($i = 0; $i < 200; ++$i) {
            $clients[$i] = ws_connect($addr, '/ws/echo');
        }
        foreach ($clients as $i => $conn) {
            ws_send($conn, 1, "hello $i");
        }
        foreach ($clients as $i => $conn) {
            expect(ws_read($conn))->toBe([1, "hello $i"]);
        }
        expect(rt_live($addr, 'ws', 200)[0])->toBe(200);
        for ($i = 0; $i < 20; ++$i) {
            $start = \microtime(true);
            expect(psr15_get($addr, '/hello')['body'])->toBe('Hello');
            expect(\microtime(true) - $start)->toBeLessThan(1.0);
        }
        foreach ($clients as $conn) {
            ws_send($conn, 8, \pack('n', 1000));
        }
        foreach ($clients as $conn) {
            ws_expect_close($conn, 1000);
        }
        expect(rt_live($addr, 'ws', 0)[0])->toBe(0);
    } finally {
        native_stop($process);
    }
    rt_clean($log);
})->with('modes');

test('a drain (SIGTERM) with sockets open closes every one with 1001, and swerve exits 0 with a clean log', function (array $mode) {
    [$process, $addr, $log] = rt_start($mode, 2);
    $clients                = [];
    foreach (['/ws/echo', '/ws/echo', '/ws/news', '/ws/me', '/ws/news', '/ws/echo'] as $path) {
        $clients[] = ws_connect($addr, $path);
    }
    ws_send($clients[0], 1, 'hi');
    expect(ws_read($clients[0]))->toBe([1, 'hi']);
    expect(ws_read($clients[2]))->toBe([1, 'subscribed']);
    expect(ws_read($clients[4]))->toBe([1, 'subscribed']);

    \posix_kill(\proc_get_status($process)['pid'], \SIGTERM);
    foreach ($clients as $i => $conn) {
        expect(ws_read($conn))->toBe([8, \pack('n', 1001)], "client $i");
        ws_send($conn, 8, \pack('n', 1001));
        \fread($conn, 1);
        \fclose($conn);
    }
    [$code, $seconds] = swerve_wait($process, 8);

    expect($code)->toBe(0);
    expect($seconds)->toBeLessThan(5.0, (string) \file_get_contents($log));
    rt_clean($log);
})->with('modes');
