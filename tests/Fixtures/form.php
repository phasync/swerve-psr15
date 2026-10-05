<?php

// The adapter's parsing, in the reference's format (form-reference.php)
use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $walk = static function ($f) use (&$walk) {
            if ($f instanceof UploadedFileInterface) {
                return ['name' => $f->getClientFilename(), 'type' => $f->getClientMediaType(), 'size' => $f->getSize(), 'error' => $f->getError(),
                    'md5'      => \UPLOAD_ERR_OK === $f->getError() ? \md5((string) $f->getStream()) : null];
            }

            return \array_map($walk, $f);
        };

        return new Response(200, ['Content-Type' => 'application/json'], \json_encode(['post' => $request->getParsedBody(), 'files' => $walk($request->getUploadedFiles()), 'input' => (string) $request->getBody()]));
    }
};
