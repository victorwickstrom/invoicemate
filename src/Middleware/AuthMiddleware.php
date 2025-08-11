<?php
namespace Invoicemate\Middleware;

use Invoicemate\Utils\JWT;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Middleware that validates the Authorization header and decodes the JWT.
 */
class AuthMiddleware implements MiddlewareInterface
{
    private JWT $jwt;
    private ResponseFactory $responseFactory;

    public function __construct(JWT $jwt, ResponseFactory $responseFactory)
    {
        $this->jwt = $jwt;
        $this->responseFactory = $responseFactory;
    }

    public function process(Request $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $auth = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(\S+)/', $auth, $matches)) {
            return $this->unauthorized('Missing token');
        }
        $token = $matches[1];
        try {
            $claims = $this->jwt->decode($token);
        } catch (\Throwable $e) {
            return $this->unauthorized('Invalid token');
        }
        $request = $request->withAttribute('user', $claims);
        return $handler->handle($request);
    }

    private function unauthorized(string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(401);
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}