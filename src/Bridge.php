<?php

namespace Swerve\Psr15;

use phasync\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\ClientRequest;
use Swerve\RequestHandler;

/**
 * Turns a swerve {@see ClientRequest} into a PSR-7 request for a PSR-15 handler, and the PSR-7
 * response it returns into the ClientRequest's response.
 *
 * Both bodies stream. The request body is a lazy stream on the connection: nothing is read until
 * the application reads, and `Expect: 100-continue` is answered by that first read. A POST form
 * (`application/x-www-form-urlencoded` or `multipart/form-data`) is parsed into `getParsedBody()`
 * and `getUploadedFiles()` when asked for, as PHP fills `$_POST` and `$_FILES`; any other body is
 * left raw. The response body is read in chunks and written as it is read, so a body larger than
 * memory is fine as long as its stream produces it piecemeal. The response has a Content-Length
 * when the application declared one or its body knows its size, and is otherwise chunked.
 *
 * An exception from the handler is swerve's to deal with: a 500 when the head was not sent yet,
 * an aborted connection after, logged either way.
 *
 * ```php
 * // swerve.php: any PSR-15 handler, with this adapter's help
 * return Swerve\Psr15\Bridge::wrap($app);
 * ```
 */
final class Bridge
{
    private const CHUNK = 65536;

    private function __construct()
    {
    }

    /**
     * The swerve handler that serves each exchange with `$app`.
     *
     * Usable as `swerve.php`'s return value, or by an entry function that builds the application.
     */
    public static function wrap(RequestHandlerInterface $app): RequestHandler
    {
        return new RequestHandler(static function (ClientRequest $client) use ($app): void {
            self::send($client, $app->handle(self::request($client)));
        });
    }

    /**
     * The PSR-7 server request for an exchange; its body is not read.
     */
    private static function request(ClientRequest $client): ServerRequestInterface
    {
        $method  = $client->getMethod();
        $target  = $client->getTarget();
        $headers = $client->getRequestHeaders();
        $now     = \microtime(true);
        $server  = [
            'REQUEST_METHOD'     => $method,
            'REQUEST_URI'        => $target,
            'QUERY_STRING'       => \parse_url($target, \PHP_URL_QUERY) ?: '',
            'SERVER_PROTOCOL'    => 'HTTP/' . $client->getProtocolVersion(),
            'REQUEST_TIME'       => (int) $now,
            'REQUEST_TIME_FLOAT' => $now,
        ];
        if ('https' === $client->getScheme()) {
            $server['HTTPS'] = 'on';
        }
        if (\preg_match('/^\[?(.+?)\]?:(\d+)$/', $client->peer(), $peer)) {
            $server['REMOTE_ADDR'] = $peer[1];
            $server['REMOTE_PORT'] = (int) $peer[2];
        }
        if (\preg_match('/^\[?(.+?)\]?:(\d+)$/', $client->local(), $local)) {
            $server['SERVER_ADDR'] = $local[1];
            $server['SERVER_PORT'] = (int) $local[2];
        }

        // A request has a body when it says so; its length is known unless it is chunked
        $size    = isset($headers['transfer-encoding']) ? null : (int) ($headers['content-length'][0] ?? 0);
        $body    = new RequestBody($client, $size);
        $form    = isset($headers['content-type']) ? FormBody::for($method, $headers['content-type'][0], $body) : null;
        $cookies = isset($headers['cookie']) ? ServerRequest::cookies(\implode('; ', $headers['cookie'])) : [];

        return new ServerRequest(
            $method,
            $target,
            $form ? $form->input(...) : $body,
            $headers,
            null,
            $server,
            $cookies,
            $form ? $form->files(...) : [],
            $form ? $form->fields(...) : null,
            [],
            $client->getProtocolVersion(),
        );
    }

    /**
     * Send a PSR-7 response: the head, the body in chunks as the stream yields them, and the end.
     */
    private static function send(ClientRequest $client, ResponseInterface $response): void
    {
        $body    = $response->getBody();
        $headers = $response->getHeaders();
        $size    = $body->getSize();
        if (null !== $size && $size > 0 && !$response->hasHeader('Content-Length')) {
            $headers['Content-Length'] = (string) $size;
        }
        $client->sendResponseHeaders($response->getStatusCode(), $headers);
        if ('HEAD' !== $client->getMethod()) {
            if ($body->isSeekable()) {
                $body->rewind(); // a response is built by writing to its body, which leaves it at the end
            }
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK);
                if ('' !== $chunk) {
                    $client->write($chunk);
                }
            }
        }
        $client->end();
    }
}
