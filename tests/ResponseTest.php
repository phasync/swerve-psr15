<?php

/*
 * The two response classes as middleware sees them: ordinary PSR-7 responses whose clones keep what
 * the adapter needs.
 */

use phasync\Psr\Response;
use phasync\Psr\ServerRequest;
use phasync\Psr\UnbufferedStream;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Psr15\EventStreamResponse;
use Swerve\Psr15\WebSocketResponse;
use Swerve\ServerSentEvents;
use Swerve\WebSocket;

/** The PSR request of a WebSocket handshake, as the bridge builds it: lowercase names; $changes replace or (null) remove a header; $bodySize is the body's length, null for one of unknown length. */
function handshake_request(array $changes = [], string $method = 'GET', ?int $bodySize = 0): ServerRequestInterface
{
    $headers = [
        'host'                  => ['t'],
        'upgrade'               => ['websocket'],
        'connection'            => ['Upgrade'],
        'sec-websocket-key'     => ['dGhlIHNhbXBsZSBub25jZQ=='],
        'sec-websocket-version' => ['13'],
    ];
    foreach ($changes as $name => $value) {
        null === $value ? \array_splice($headers, \array_search($name, \array_keys($headers), true), 1) : $headers[$name] = (array) $value;
    }

    return new ServerRequest($method, '/ws', null === $bodySize ? new UnbufferedStream() : \str_repeat('x', $bodySize), $headers);
}

test('a valid handshake gives a 101 response with the swerve headers, the handler and the limit', function () {
    $handler  = static function (WebSocket $ws) {
    };
    $response = WebSocketResponse::from(handshake_request(), $handler, maxMessage: 123);

    expect($response)->toBeInstanceOf(WebSocketResponse::class)->toBeInstanceOf(Response::class);
    expect($response->getStatusCode())->toBe(101);
    expect($response->getHeaders())->toBe(['upgrade' => ['websocket'], 'connection' => ['Upgrade'], 'sec-websocket-accept' => ['s3pPLMBiTxaQ9kYGzzhZRbK+xOo=']]);
    expect($response->callback)->toEqual($handler(...));
    expect($response->maxMessage)->toBe(123);
    expect(WebSocketResponse::from(handshake_request(), $handler)->maxMessage)->toBe(WebSocket::MAX_MESSAGE);
});

test('the 101 response survives the clones that middleware makes, and keeps the headers it added', function () {
    $handler  = static function (WebSocket $ws) {
    };
    $response = WebSocketResponse::from(handshake_request(), $handler, maxMessage: 123);

    $clone = $response->withHeader('X-A', '1')->withAddedHeader('X-A', '2')->withoutHeader('X-B')->withProtocolVersion('1.1');

    expect($clone)->toBeInstanceOf(WebSocketResponse::class)->not->toBe($response);
    expect($clone->callback)->toEqual($handler(...));
    expect($clone->maxMessage)->toBe(123);
    expect($clone->getStatusCode())->toBe(101);
    expect($clone->getHeader('X-A'))->toBe(['1', '2']);
    expect($clone->getHeader('Sec-WebSocket-Accept'))->toBe(['s3pPLMBiTxaQ9kYGzzhZRbK+xOo=']);
    expect($response->hasHeader('X-A'))->toBeFalse();
});

test('a refusal is an ordinary PSR response, built as swerve answers it', function (array $changes, string $method, ?int $bodySize, int $status, string $body, array $header) {
    $response = WebSocketResponse::from(handshake_request($changes, $method, $bodySize), static fn () => null, origins: ['https://good.example']);

    expect($response)->not->toBeInstanceOf(WebSocketResponse::class);
    expect($response->getStatusCode())->toBe($status);
    expect((string) $response->getBody())->toBe($body);
    expect($response->getHeaderLine('Content-Length'))->toBe((string) \strlen($body));
    expect($response->getHeaderLine('Content-Type'))->toBe('text/plain');
    expect($response->getHeaderLine(\key($header)))->toBe(\current($header));
    expect($response->withHeader('X-Test', '1')->getHeaderLine('X-Test'))->toBe('1'); // middleware decorates it
})->with([
    'not a handshake: 426'         => [['upgrade' => null], 'GET', 0, 426, 'This address speaks WebSocket', ['Upgrade' => 'websocket']],
    'not a valid handshake: 400'   => [['sec-websocket-version' => '8'], 'GET', 0, 400, 'Not a WebSocket handshake', ['Sec-WebSocket-Version' => '13']],
    'a POST: 400'                  => [[], 'POST', 0, 400, 'Not a WebSocket handshake', ['Sec-WebSocket-Version' => '13']],
    'a body: 400'                  => [[], 'GET', 5, 400, 'Not a WebSocket handshake', ['Sec-WebSocket-Version' => '13']],
    'an origin not allowed: 403'   => [['origin' => 'https://evil.example'], 'GET', 0, 403, 'This origin may not connect', ['Content-Type' => 'text/plain']],
]);

test('a body of unknown length (chunked) is a body: 400', function () {
    $response = phasync::run(fn () => WebSocketResponse::from(handshake_request(bodySize: null), static fn () => null));

    expect($response->getStatusCode())->toBe(400);
});

test('origins and subprotocols are decided by swerve\'s handshake, from the PSR request', function () {
    $noop = static fn () => null;

    expect(WebSocketResponse::from(handshake_request(['origin' => 'HTTPS://Good.example']), $noop, origins: ['https://good.example'])->getStatusCode())->toBe(101);
    expect(WebSocketResponse::from(handshake_request(), $noop, origins: ['https://good.example'])->getStatusCode())->toBe(101);
    expect(WebSocketResponse::from(handshake_request(['origin' => 'https://evil.example']), $noop)->getStatusCode())->toBe(101);

    $offer = handshake_request(['sec-websocket-protocol' => 'v1.chat, other, v2.chat']);
    expect(WebSocketResponse::from($offer, $noop, ['v2.chat', 'v1.chat'])->getHeaderLine('Sec-WebSocket-Protocol'))->toBe('v2.chat');
    expect(WebSocketResponse::from($offer, $noop, ['nothing'])->hasHeader('Sec-WebSocket-Protocol'))->toBeFalse();
    expect(WebSocketResponse::from($offer, $noop)->hasHeader('Sec-WebSocket-Protocol'))->toBeFalse();
});

test('header names of any case on the PSR request are read', function () {
    $request = (new ServerRequest('GET', '/ws', ''))
        ->withHeader('Upgrade', 'websocket')->withHeader('Connection', 'Upgrade')->withHeader('Sec-WebSocket-Key', 'dGhlIHNhbXBsZSBub25jZQ==')->withHeader('Sec-WebSocket-Version', '13');

    expect(WebSocketResponse::from($request, static fn () => null)->getStatusCode())->toBe(101);
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
