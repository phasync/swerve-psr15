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
