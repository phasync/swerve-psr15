<?php

/*
 * A Slim 4 application, with Slim's PSR-17 factories being phasync's, except that its responses
 * get a writable body: PsrFactory::createResponse() gives a read-only one (phasync/phasync#82).
 */

use phasync\Psr\PsrFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/streams.php';

$factory = new class extends PsrFactory {
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new phasync\Psr\Response($code, [], \fopen('php://temp', 'w+'), '1.1', $reasonPhrase);
    }
};
$app = AppFactory::create($factory);

/** A middleware that notes in the request when it was entered, and in the response when it was left. */
$noting = static fn (string $name) => static function (Request $request, Handler $next) use ($name): Response {
    $response = $next->handle($request->withAttribute('trail', [...$request->getAttribute('trail', []), $name]));

    return $response->withHeader('X-Out', ($response->hasHeader('X-Out') ? $response->getHeaderLine('X-Out') . ',' : '') . $name);
};
$app->add($noting('A'));
$app->add($noting('B'));
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, false, false);

$json = static function (Response $response, mixed $data, int $status = 200): Response {
    $response->getBody()->write(\json_encode($data));

    return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
};

$app->get('/hello', function (Request $request, Response $response) {
    $response->getBody()->write('Hello');

    return $response;
});

$app->get('/users/{id:[0-9]+}/posts/{slug}', function (Request $request, Response $response, array $args) use ($json) {
    return $json($response, $args);
});

$app->post('/json', function (Request $request, Response $response) use ($json) {
    return $json($response, ['received' => $request->getParsedBody(), 'type' => $request->getHeaderLine('Content-Type')], 201);
});

$app->get('/order', function (Request $request, Response $response) use ($json) {
    return $json($response, $request->getAttribute('trail'));
});

$app->get('/headers', function (Request $request, Response $response) use ($json) {
    return $json($response, ['x' => $request->getHeader('X-Test'), 'cookies' => $request->getCookieParams(), 'cased' => $request->getHeaderLine('x-TEST')])
        ->withAddedHeader('Set-Cookie', 'a=1; Path=/')
        ->withAddedHeader('Set-Cookie', 'b=2; HttpOnly')
        ->withHeader('X-Reply', 'yes');
});

$app->get('/info', function (Request $request, Response $response) use ($json) {
    $server = $request->getServerParams();

    return $json($response, [
        'method'  => $request->getMethod(),
        'uri'     => (string) $request->getUri(),
        'target'  => $request->getRequestTarget(),
        'query'   => $request->getQueryParams(),
        'version' => $request->getProtocolVersion(),
        'remote'  => $server['REMOTE_ADDR'] ?? null,
        'https'   => $server['HTTPS'] ?? null,
        'port'    => $server['SERVER_PORT'] ?? null,
    ]);
});

$app->get('/empty', fn (Request $request, Response $response) => $response->withStatus(204));

$app->get('/slow', function (Request $request, Response $response) {
    phasync::sleep(1.0);
    $response->getBody()->write('slow');

    return $response;
});

// Responds once n bytes of the body have arrived, whatever else the client sends later
$app->post('/first/{n}', function (Request $request, Response $response, array $args) {
    $body = $request->getBody();
    $data = '';
    while (\strlen($data) < (int) $args['n']) {
        $data .= $body->read((int) $args['n'] - \strlen($data));
    }
    $response->getBody()->write($data);

    return $response;
});

$app->post('/echo-stream', function (Request $request, Response $response) {
    return $response->withBody($request->getBody());
});

$app->post('/count', function (Request $request, Response $response) {
    $body = $request->getBody();
    $hash = \hash_init('md5');
    $size = 0;
    while (!$body->eof()) {
        $data  = $body->read(8192);
        $size += \strlen($data);
        \hash_update($hash, $data);
    }
    $response->getBody()->write($size . ':' . \hash_final($hash));

    return $response;
});

// A body of n MiB that exists a chunk at a time: of unknown size (chunked), or sized
$app->get('/big/{mb}', fn (Request $request, Response $response, array $args) => $response->withBody(new GeneratedStream($args['mb'] << 20, false)));
$app->get('/big-sized/{mb}', fn (Request $request, Response $response, array $args) => $response->withBody(new GeneratedStream($args['mb'] << 20, true)));

return $app;
