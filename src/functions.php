<?php

namespace Swerve\Psr15;

use Psr\Http\Server\RequestHandlerInterface;
use Swerve\RequestHandler;

/**
 * The entry point of the `psr15` swerve adapter, which swerve's workers call once each, after the
 * fork, with the application directory (see the `extra.swerve` section of this package's composer.json).
 *
 * Requires `swerve.psr15.php` in the application directory, which returns the application's PSR-15
 * request handler: a Slim `App`, a Mezzio application, or your own.
 *
 * ```php
 * // swerve.psr15.php
 * $app = Slim\Factory\AppFactory::create(new phasync\Psr\PsrFactory());
 * $app->get('/', fn ($request, $response) => $response);
 *
 * return $app;
 * ```
 *
 * @param string $appDir the application directory: where swerve was started, or its `swerve.php` argument's
 *
 * @throws \RuntimeException         when `swerve.psr15.php` does not exist
 * @throws \UnexpectedValueException when it returns anything but a `Psr\Http\Server\RequestHandlerInterface`
 */
function entry(string $appDir): RequestHandler
{
    $file = "$appDir/swerve.psr15.php";
    if (!\is_file($file)) {
        throw new \RuntimeException("$file not found: it returns the application's Psr\\Http\\Server\\RequestHandlerInterface");
    }
    $app = require $file;
    if (!$app instanceof RequestHandlerInterface) {
        throw new \UnexpectedValueException("$file returned " . \get_debug_type($app) . '; it must return a Psr\\Http\\Server\\RequestHandlerInterface');
    }

    return Bridge::wrap($app);
}
