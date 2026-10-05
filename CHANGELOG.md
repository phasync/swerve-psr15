# Changelog

## Unreleased

### Added

- `Swerve\Psr15\entry()`, the adapter's entry point for swerve: it requires `swerve.psr15.php`, which
  returns a `Psr\Http\Server\RequestHandlerInterface`, and serves it.
- `Swerve\Psr15\Bridge::wrap()`, which turns any PSR-15 handler into a `Swerve\RequestHandler`: the
  request is a `phasync\Psr\ServerRequest` over a lazy, streaming body, with PHP-compatible form parsing
  (`getParsedBody()`, `getUploadedFiles()`), and the response is streamed to the client in chunks.
- `Swerve\Psr15\WebSocketResponse::from($request, $handler, ...)`: takes the PSR request, checks the
  handshake with swerve's `WebSocket::handshake()` and returns an ordinary PSR-7 response: the refusal
  swerve would send (426, 400 or 403), which middleware decorates like any response, or a real `101`
  with the handshake headers, which middleware may add headers to and whose clones keep the handler and
  the message size limit. The bridge hands the connection to swerve with the response's headers as they
  are (`WebSocket::upgrade()`). The handler is swerve's `WebSocket`, the same in every adapter; there are
  subprotocols, an origin allow-list and a message size limit.
- `Swerve\Psr15\EventStreamResponse::stream()`: a PSR-7 response that streams Server-Sent Events from a
  callback receiving swerve's `ServerSentEvents`; the headers on the response, including those that
  middleware added, are sent with the head.
