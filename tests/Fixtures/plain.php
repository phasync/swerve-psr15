<?php

/*
 * A bare PSR-15 handler, for what a framework would catch before swerve sees it.
 */

use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require_once __DIR__ . '/streams.php';

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/throw'        => throw new RuntimeException('the handler failed'),
            '/stream-throw' => new Response(200, ['Content-Type' => 'text/plain'], new FailingStream()),
            default         => new Response(200, ['Content-Type' => 'text/plain'], 'ok'),
        };
    }
};
