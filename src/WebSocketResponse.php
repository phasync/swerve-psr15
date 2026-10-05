<?php

namespace Swerve\Psr15;

use phasync\Psr\Response;
use Swerve\WebSocket;

/**
 * A PSR-7 response that asks the adapter to upgrade the connection to a WebSocket.
 *
 * A PSR-15 handler (a Slim route, or a middleware stack around it) returns it like any response;
 * {@see Bridge} then answers the handshake and runs the callback on the open socket, with swerve's
 * {@see WebSocket}. The codec, the close codes and the limits are swerve's: see its
 * `docs/websocket.md`.
 *
 * ```php
 * $app->get('/chat', function ($request, $response) {
 *     $user = $request->getAttribute('user');   // read the request now: the callback runs after the handler returned
 *
 *     return WebSocketResponse::serve(function (WebSocket $ws) use ($user) {
 *         foreach ($ws as $message) {
 *             $ws->send("$user: $message");
 *         }
 *     });
 * });
 * ```
 *
 * It is an ordinary immutable response, with the placeholder status 101: middleware may `with*()` it
 * and the clones keep the callback and the options. A handshake that swerve refuses (426 for a plain
 * GET, 400 for an invalid handshake, 403 for an origin that is not allowed) is answered by swerve
 * itself, and neither a refusal nor the 101 carries the headers that middleware added to this response.
 *
 * @see EventStreamResponse
 */
final class WebSocketResponse extends Response
{
    /**
     * @param \Closure(WebSocket): void $callback
     * @param string[]                  $subprotocols
     * @param string[]|null             $origins
     */
    private function __construct(
        public readonly \Closure $callback,
        public readonly array $subprotocols,
        public readonly ?array $origins,
        public readonly int $maxMessage,
    ) {
        parent::__construct(101);
    }

    /**
     * A response that upgrades the request to a WebSocket and runs `$callback` on it.
     *
     * The callback runs after the handler returned, in a coroutine of its own, and the connection
     * closes when it returns (1000) or throws (1011, logged). It is given swerve's `WebSocket`;
     * the arguments are those of {@see WebSocket::serve()}.
     *
     * @param callable(WebSocket): void $callback
     * @param string[]                  $subprotocols the subprotocols you speak, in your order of preference; the first one the client offered is chosen
     * @param string[]|null             $origins      the allowed `Origin`s (compared case-insensitively), null: any; a client that sends none is let through
     * @param int                       $maxMessage   the largest message received, in bytes; a larger one closes with 1009
     */
    public static function serve(callable $callback, array $subprotocols = [], ?array $origins = null, int $maxMessage = WebSocket::MAX_MESSAGE): self
    {
        return new self($callback(...), $subprotocols, $origins, $maxMessage);
    }
}
