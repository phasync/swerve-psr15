<?php

/*
 * The two response classes as middleware sees them: ordinary PSR-7 responses whose clones keep what
 * the adapter needs.
 */

use Swerve\Psr15\EventStreamResponse;
use Swerve\Psr15\WebSocketResponse;
use Swerve\ServerSentEvents;
use Swerve\WebSocket;

test('a WebSocketResponse survives the clones that middleware makes, with its callback and options', function () {
    $callback = static function (WebSocket $ws) {
    };
    $response = WebSocketResponse::serve($callback, ['chat'], ['https://a.example'], 123);

    $clone = $response->withHeader('X-A', '1')->withAddedHeader('X-A', '2')->withStatus(200)->withoutHeader('X-B')->withProtocolVersion('1.1');

    expect($clone)->toBeInstanceOf(WebSocketResponse::class)->not->toBe($response);
    expect($clone->callback)->toEqual($callback(...));
    expect($clone->subprotocols)->toBe(['chat']);
    expect($clone->origins)->toBe(['https://a.example']);
    expect($clone->maxMessage)->toBe(123);
    expect($clone->getHeader('X-A'))->toBe(['1', '2']);
    expect($response->getStatusCode())->toBe(101);
    expect($response->hasHeader('X-A'))->toBeFalse();
});

test('a WebSocketResponse has the defaults of WebSocket::serve()', function () {
    $response = WebSocketResponse::serve(static fn () => null);

    expect([$response->subprotocols, $response->origins, $response->maxMessage])->toBe([[], null, WebSocket::MAX_MESSAGE]);
});

test('an EventStreamResponse survives clones, and carries the headers it was given', function () {
    $callback = static function (ServerSentEvents $sse) {
    };
    $response = EventStreamResponse::stream($callback, ['Access-Control-Allow-Origin' => '*']);

    $clone = $response->withHeader('Vary', 'Origin');

    expect($clone)->toBeInstanceOf(EventStreamResponse::class);
    expect($clone->callback)->toEqual($callback(...));
    expect($clone->getStatusCode())->toBe(200);
    expect($clone->getHeaders())->toBe(['Access-Control-Allow-Origin' => ['*'], 'Vary' => ['Origin']]);
});
