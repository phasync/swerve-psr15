# Changelog

## Unreleased

### Added

- `Swerve\Psr15\entry()`, the adapter's entry point for swerve: it requires `swerve.psr15.php`, which
  returns a `Psr\Http\Server\RequestHandlerInterface`, and serves it.
- `Swerve\Psr15\Bridge::wrap()`, which turns any PSR-15 handler into a `Swerve\RequestHandler`: the
  request is a `phasync\Psr\ServerRequest` over a lazy, streaming body, with PHP-compatible form parsing
  (`getParsedBody()`, `getUploadedFiles()`), and the response is streamed to the client in chunks.
- `Swerve\Psr15\WebSocketResponse::serve()`: a PSR-7 response that upgrades the request to swerve's
  `WebSocket` and runs a callback on it, with subprotocols, an origin allow-list and a message size limit.
  It survives the clones that middleware makes.
- `Swerve\Psr15\EventStreamResponse::stream()`: a PSR-7 response that streams Server-Sent Events from a
  callback receiving swerve's `ServerSentEvents`; the headers on the response, including those that
  middleware added, are sent with the head.
