# swerve for PSR-15

[![CI](https://github.com/phasync/swerve-psr15/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/swerve-psr15/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/phasync/swerve-psr15)](https://packagist.org/packages/phasync/swerve-psr15)
[![PHP](https://img.shields.io/packagist/dependency-v/phasync/swerve-psr15/php)](https://packagist.org/packages/phasync/swerve-psr15)
![License](https://img.shields.io/github/license/phasync/swerve-psr15)

**Any PSR-15 request handler, booted once per worker.** [swerve](https://github.com/phasync/swerve)
is a PHP application server: long-running workers that serve HTTP/1.1 themselves and stream request
and response bodies. This package runs a `Psr\Http\Server\RequestHandlerInterface` on it: Slim,
Mezzio, or your own.

```bash
composer require phasync/swerve-psr15
```

Create `swerve.psr15.php` next to `composer.json`. It returns the handler:

```php
<?php // swerve.psr15.php

use Slim\Factory\AppFactory;

$app = AppFactory::create();
$app->get('/hello/{name}', function ($request, $response, array $args) {
    $response->getBody()->write("Hello, {$args['name']}");

    return $response;
});

return $app;
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080
```

The file is required once in each worker, after `vendor/autoload.php`, and the handler it returns
serves every request that worker receives. Anything else than a `RequestHandlerInterface` stops swerve
at start with exit code 2 and says what was returned.

## How requests and responses cross

- The request is a `phasync\Psr\ServerRequest`. Its body is a lazy stream on the connection: nothing is
  read until the application reads, so a large upload costs no memory, and `Expect: 100-continue` is
  answered by that first read. swerve's `--max-body` (8 MiB by default) limits it.
- A POST of `application/x-www-form-urlencoded` or `multipart/form-data` is parsed as PHP parses it
  (including `max_input_vars`, `post_max_size`, `upload_max_filesize` and `max_file_uploads`) when the
  application asks for `getParsedBody()` or `getUploadedFiles()`. Any other body stays raw.
- The response body is read in chunks and written as it is read, so a body larger than memory is fine
  if its stream produces it piecemeal. It has a `Content-Length` when the application set one or the
  stream knows its size, and is otherwise sent chunked.
- A handler that waits (`phasync::sleep()`, a database or HTTP client built on phasync) lets the same
  worker serve other requests meanwhile.
- An exception from the handler is logged, and answered with a 500 if the head was not sent yet; after
  that, the connection is aborted.

## WebSockets and Server-Sent Events

A handler asks for a WebSocket or an event stream by returning a response. swerve itself speaks the
protocols (see its `docs/websocket.md`: close codes, limits, what a drain does); this package only
lets a PSR-15 route or middleware stack ask for one.

```php
use Swerve\Psr15\EventStreamResponse;
use Swerve\Psr15\WebSocketResponse;
use Swerve\ServerSentEvents;
use Swerve\Swerve;
use Swerve\WebSocket;

$app->get('/chat', function ($request, $response) {
    $user = $request->getAttribute('user');          // read the request now, see below

    return WebSocketResponse::from($request, function (WebSocket $ws) use ($user) {
        foreach ($ws as $message) {                  // ends when the client leaves
            $ws->send("$user: $message");
        }
    }, subprotocols: ['chat.v1'], origins: ['https://example.com']);
});

$app->get('/news', function ($request, $response) {
    return EventStreamResponse::stream(function (ServerSentEvents $sse) {
        foreach (Swerve::subscribe('news', heartbeat: 15) as $message) {
            null === $message ? $sse->comment('keep-alive') : $sse->send($message, event: 'news');
        }
    });
});

$app->post('/news', function ($request, $response) {
    Swerve::publish('news', (string) $request->getBody());   // reaches every open socket and stream, on every worker

    return $response->withStatus(202);
});
```

- `WebSocketResponse::from($request, $handler, $subprotocols = [], $origins = null, $maxMessage = WebSocket::MAX_MESSAGE)`
  and `EventStreamResponse::stream($callback, $headers = [])` return ordinary PSR-7 responses.
  Middleware may `with*()` them: the clones keep the handler and the options.
- **The handler is swerve's `WebSocket`**, the same class in every framework adapter, so what
  swerve's `docs/websocket.md` shows works here unchanged.
- **The handler runs after the route returned.** Take the user, the session or anything else from the
  PSR request before returning the response, and close over it with `use`. Do not keep the request
  itself for the handler.
- **The handshake is checked in `from()`, by swerve's own decision, and the answer is a PSR response.**
  A request that is not a handshake gets 426, an invalid one 400 (naming version 13) and an origin that
  `$origins` does not allow 403: ordinary responses, built as swerve itself would answer, so middleware
  decorates them like any other. A handshake gets a real `101` response with `Upgrade`, `Connection`,
  `Sec-WebSocket-Accept` and the chosen `Sec-WebSocket-Protocol`; middleware may add headers to it (the
  `Access-Control-*` or `Set-Cookie` of the application's middleware are on the `101` the client sees),
  and the clones keep the handler and the limit.
- **The `101`'s body is the outbound WebSocket frames; the request's body is read for the inbound ones.**
  A middleware that `withBody()`s the response sees every frame the client is sent, and one that
  `withBody()`s the request before the route sees every frame the client sent, like any other body.
  Nothing of the handler runs, and no coroutine is touched, before the bridge's first read of that body:
  a middleware that changes the status of the `101` response (a `403`, say) has refused the upgrade, and
  it is sent as any response — the handler never starts.
- The event stream has the headers of the response that reaches the adapter, so the `Access-Control-*` or
  `Vary` that middleware added are sent. The status is always 200, and `Content-Type`, `Cache-Control` and
  `X-Accel-Buffering` are swerve's: the response's own are ignored. `Last-Event-ID` is on the PSR request and
  on `ServerSentEvents::lastEventId()`.
- A `HEAD` request to an event stream gets the head and no body; the callback does not run.
- The stream ends when the callback returns; a callback that throws is logged and the connection aborted.
  A client that left makes the next `send()` throw `phasync\IOException`: let it leave the callback.

## Other ways to run an application on swerve

swerve picks the adapter from what is installed, so `--adapter=` is only needed when several are.

- The default adapter: `swerve.php` returns a `Swerve\RequestHandler`, written against swerve's own
  `ClientRequest`. Use it for anything that is not a PSR-15 application.
- `phasync/swerve-sapi` runs applications written for PHP's superglobals and `header()`.
- `phasync/swerve-symfony` runs Symfony's kernel.

`Swerve\Psr15\Bridge::wrap($handler)` returns the `Swerve\RequestHandler` this package builds, for a
`swerve.php` that wants a PSR-15 handler beside something of its own.

## Development

```bash
composer install
vendor/bin/pest
```

The tests start a real `vendor/bin/swerve` and speak HTTP to it over sockets. To run them with the
phasync extension, put it in an ini file that PHP scans, as swerve's processes inherit it:
`PHP_INI_SCAN_DIR=:/dir/with/phasync.ini vendor/bin/pest`.

MIT licensed.
