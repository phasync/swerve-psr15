# Changelog

## Unreleased

### Added

- `Swerve\Psr15\entry()`, the adapter's entry point for swerve: it requires `swerve.psr15.php`, which
  returns a `Psr\Http\Server\RequestHandlerInterface`, and serves it.
- `Swerve\Psr15\Bridge::wrap()`, which turns any PSR-15 handler into a `Swerve\RequestHandler`: the
  request is a `phasync\Psr\ServerRequest` over a lazy, streaming body, with PHP-compatible form parsing
  (`getParsedBody()`, `getUploadedFiles()`), and the response is streamed to the client in chunks.
