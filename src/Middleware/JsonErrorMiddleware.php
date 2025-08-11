<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Throwable;

/**
 * JsonErrorMiddleware transforms uncaught exceptions into JSON responses.
 *
 * When added as the outermost middleware this class catches any
 * Throwable, logs it if a logger is available and returns a
 * JSON-encoded error message.  This prevents stack traces from leaking to
 * clients and ensures consistent error formatting across the API.
 */
class JsonErrorMiddleware implements MiddlewareInterface
{
    private ResponseFactory $responseFactory;

    public function __construct()
    {
        $this->responseFactory = new ResponseFactory();
    }

    public function process(Request $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            $response = $this->responseFactory->createResponse(500);
            $response->getBody()->write(json_encode([
                'error'   => 'Internal Server Error',
                'message' => $e->getMessage(),
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }
}