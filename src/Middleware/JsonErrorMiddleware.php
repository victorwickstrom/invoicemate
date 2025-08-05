<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response;

/**
 * JsonErrorMiddleware captures exceptions thrown during request processing
 * and returns structured JSON responses with appropriate HTTP status codes.
 * It handles common HTTP exceptions separately (404, 401, 422) and falls
 * back to a generic 500 Internal Server Error for all other throwables.
 */
class JsonErrorMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            $status = $e->getCode() ?: 500;
            $message = $e->getMessage();
            return $this->errorResponse($status, $message);
        } catch (\Throwable $e) {
            // Unexpected error
            return $this->errorResponse(500, 'Internal Server Error');
        }
    }

    private function errorResponse(int $status, string $message): ResponseInterface
    {
        $response = new Response();
        $payload  = json_encode([
            'error'   => $message,
            'status'  => $status,
        ], JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}