<?php

/*
 * A Slim 4 application with WebSocket and Server-Sent Events routes, behind a middleware that
 * clones every response (as CORS and caching middleware do). /live tells how many callbacks of
 * a kind are running in the worker that answers.
 */

use phasync\Psr\PsrFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Factory\AppFactory;
use Swerve\Psr15\EventStreamResponse;
use Swerve\Psr15\WebSocketResponse;
use Swerve\ServerSentEvents;
use Swerve\Swerve;
use Swerve\WebSocket;

/**
 * A passthrough PSR-7 stream that appends every chunk read() gives out to a log file: what a CORS
 * or logging middleware looks like when it wraps a WebSocket body (outbound, response; inbound,
 * request) to see the frames flowing through it, as docs/websocket.md's adapters section describes.
 */
final class LoggingStream implements StreamInterface
{
    public function __construct(private readonly StreamInterface $inner, private readonly string $log)
    {
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek($offset, $whence = \SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    public function write($string): int
    {
        return $this->inner->write($string);
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read($length): string
    {
        $chunk = $this->inner->read($length);
        if ('' !== $chunk) {
            \file_put_contents($this->log, $chunk, \FILE_APPEND | \LOCK_EX);
        }

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(65536);
        }

        return $contents;
    }

    public function getMetadata($key = null)
    {
        return $this->inner->getMetadata($key);
    }
}

$factory = new class extends PsrFactory {
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new phasync\Psr\Response($code, [], \fopen('php://temp', 'w+'), '1.1', $reasonPhrase);
    }
};
$app = AppFactory::create($factory);

$app->add(static fn (Request $request, Handler $next): Response => $next->handle($request)->withHeader('Access-Control-Allow-Origin', '*')->withHeader('X-Mw', 'seen')->withAddedHeader('Set-Cookie', 'a=1')->withAddedHeader('Set-Cookie', 'b=2'));
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, false, false);

/** Callbacks running now in this worker, by kind. */
$live = ['ws' => 0, 'sse' => 0, 'head' => 0];

/** Runs $body with $live[$kind] counted while it does. */
$counted = static function (string $kind, callable $body) use (&$live): void {
    ++$live[$kind];
    try {
        $body();
    } finally {
        --$live[$kind];
    }
};

$text = static function (Response $response, string $body): Response {
    $response->getBody()->write($body);

    return $response;
};

$app->get('/hello', fn (Request $request, Response $response) => $text($response, 'Hello'));
$app->get('/ext', fn (Request $request, Response $response) => $text($response, \extension_loaded('phasync') ? '1' : '0'));
$app->get('/live/{kind}', function (Request $request, Response $response, array $args) use ($text, &$live) {
    return $text($response, \json_encode([\getmypid(), $live[$args['kind']]]));
});
$app->post('/publish/{topic}', function (Request $request, Response $response, array $args) use ($text) {
    Swerve::publish($args['topic'], (string) $request->getBody());

    return $text($response, 'published');
});

// An echo; "bye" ends the callback
$app->get('/ws/echo', fn (Request $request) => WebSocketResponse::from($request, static function (WebSocket $ws) use ($counted) {
    $counted('ws', static function () use ($ws) {
        foreach ($ws as $message) {
            if ('bye' === $message) {
                return;
            }
            $ws->isBinary() ? $ws->sendBinary($message) : $ws->send($message);
        }
    });
}));

// The first subprotocol of the server's list that the client offered
$app->get('/ws/protocol', fn (Request $request) => WebSocketResponse::from($request, static function (WebSocket $ws) {
    $ws->send(\json_encode($ws->subprotocol));
}, ['v2.chat', 'v1.chat']));

// A middleware that turns the 101 into a refusal has refused the upgrade; the handler never runs
$app->get('/ws/denied', fn (Request $request) => WebSocketResponse::from($request, static function (WebSocket $ws) use ($counted) {
    $counted('ws', static function () use ($ws) {
        $ws->send('never');
    });
})->withStatus(403));

// A middleware wraps the response body (outbound frames) with a logging passthrough, as CORS or
// caching middleware would wrap any other body
$app->get('/ws/log-out', fn (Request $request) => WebSocketResponse::from($request, static function (WebSocket $ws) use ($counted) {
    $counted('ws', static function () use ($ws) {
        foreach ($ws as $message) {
            if ('bye' === $message) {
                return;
            }
            $up = \strtoupper($message);
            $ws->isBinary() ? $ws->sendBinary($up) : $ws->send($up);
        }
    });
}))->add(function (Request $request, Handler $next) {
    $response = $next->handle($request);
    $log      = $request->getHeaderLine('X-Log');

    return '' === $log ? $response : $response->withBody(new LoggingStream($response->getBody(), $log));
});

// A middleware wraps the request body (inbound frames) before the route, as one that checks a
// signature or logs a request body would
$app->get('/ws/log-in', fn (Request $request) => WebSocketResponse::from($request, static function (WebSocket $ws) use ($counted) {
    $counted('ws', static function () use ($ws) {
        foreach ($ws as $message) {
            if ('bye' === $message) {
                return;
            }
            $up = \strtoupper($message);
            $ws->isBinary() ? $ws->sendBinary($up) : $ws->send($up);
        }
    });
}))->add(function (Request $request, Handler $next) {
    $log = $request->getHeaderLine('X-Log');
    if ('' !== $log) {
        $request = $request->withBody(new LoggingStream($request->getBody(), $log));
    }

    return $next->handle($request);
});

$app->get('/ws/origin', fn (Request $request) => WebSocketResponse::from($request, static function (WebSocket $ws) {
    $ws->send('welcome');
}, origins: ['https://good.example']));

// Everything published to "news", forwarded
$app->get('/ws/news', fn (Request $request) => WebSocketResponse::from($request, static function (WebSocket $ws) use ($counted) {
    $news = Swerve::subscribe('news');
    $counted('ws', static function () use ($ws, $news) {
        $ws->send('subscribed');
        foreach ($news as $message) {
            $ws->send($message);
        }
    });
}));

// The user is taken from the request now, and the callback closes over it
$app->get('/ws/me', function (Request $request) {
    $user = $request->getCookieParams()['user'] ?? $request->getHeaderLine('X-User');

    return WebSocketResponse::from($request, static function (WebSocket $ws) use ($user) {
        foreach ($ws as $message) {
            $ws->send("$user: $message");
        }
    });
});

$app->get('/ws/small', fn (Request $request) => WebSocketResponse::from($request, static function (WebSocket $ws) {
    foreach ($ws as $message) {
        $ws->send($message);
    }
}, maxMessage: 10));

// Three events, then the stream ends
$app->get('/sse/events', fn () => EventStreamResponse::stream(static function (ServerSentEvents $sse) {
    $sse->send('one', 'greeting', '1');
    $sse->send("two\nlines", id: '2');
    $sse->comment('done');
}, ['X-Own' => 'given']));

// What the client last saw: from the PSR request, and from swerve's ServerSentEvents
$app->get('/sse/last', function (Request $request) {
    $header = $request->getHeaderLine('Last-Event-ID');

    return EventStreamResponse::stream(static fn (ServerSentEvents $sse) => $sse->send(\json_encode([$header, $sse->lastEventId()])));
});

$app->get('/sse/news', fn () => EventStreamResponse::stream(static function (ServerSentEvents $sse) use ($counted) {
    $news = Swerve::subscribe('news', heartbeat: 0.1);
    $counted('sse', static function () use ($sse, $news) {
        foreach ($news as $message) {
            null === $message ? $sse->comment('keep-alive') : $sse->send($message, event: 'news');
        }
    });
}));

// Counts how often its callback ran: not for a HEAD request
$app->get('/sse/head', function () use (&$live) {
    return EventStreamResponse::stream(static function (ServerSentEvents $sse) use (&$live) {
        ++$live['head'];
        $sse->send('x');
    });
});

$app->get('/sse/fail', fn () => EventStreamResponse::stream(static function (ServerSentEvents $sse) {
    $sse->send('before');
    throw new RuntimeException('the stream failed');
}));

return $app;
