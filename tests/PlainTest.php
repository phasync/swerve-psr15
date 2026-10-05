<?php

/*
 * A bare PSR-15 handler: HEAD, and an application that throws, for which swerve answers, logs it, and keeps serving.
 */

beforeEach(function () {
    [$this->process, $this->addr, $this->log] = psr15_start('plain.php', workers: 1);
});

test('a handler that throws gets a 500 and is logged, and the worker serves on', function () {
    $response = psr15_get($this->addr, '/throw');

    expect($response['status'])->toBe(500);
    expect(log_wait($this->log, '/RuntimeException: the handler failed/'))->not->toBeEmpty();
    expect(psr15_get($this->addr, '/')['body'])->toBe('ok');
});

test('a body stream that fails after the head is sent aborts the connection, and the worker serves on', function () {
    $conn = native_connect($this->addr);
    \fwrite($conn, "GET /stream-throw HTTP/1.1\r\nHost: t\r\n\r\n");
    $response = native_read_response($conn);

    expect($response['status'])->toBe(200);
    expect($response['complete'])->toBeFalse();
    expect(log_wait($this->log, '/the stream failed halfway/'))->not->toBeEmpty();
    expect(psr15_get($this->addr, '/')['body'])->toBe('ok');
});

test('a HEAD response has the length of the body a GET would have, and no body', function () {
    $conn = native_connect($this->addr);
    \fwrite($conn, "HEAD / HTTP/1.1\r\nHost: t\r\n\r\nGET / HTTP/1.1\r\nHost: t\r\n\r\n");
    $head = native_read_response($conn, true);
    $get  = native_read_response($conn);

    expect([$head['status'], $head['headers']['content-length'], $head['body']])->toBe([200, '2', '']);
    expect($get['body'])->toBe('ok');
});
