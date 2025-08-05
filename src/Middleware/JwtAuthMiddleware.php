<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * JwtAuthMiddleware inspects the Authorization header of every incoming
 * request, extracts the Bearer token and validates it using the AuthService.
 * If the token is invalid or missing the middleware short‑circuits and
 * returns a 401 JSON response.  When valid it attaches the user details
 * to the request attributes and delegates to the next handler.
 */
class JwtAuthMiddleware implements MiddlewareInterface
{
    private AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');
        // Extract the bearer token from the header
        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $this->unauthorized('Missing Authorization Bearer token');
        }
        $token = trim($matches[1]);
        $user  = $this->authService->validateToken($token);
        if ($user === null) {
            return $this->unauthorized('Invalid or expired token');
        }
        // attach user info onto request for downstream handlers
        $request = $request->withAttribute('user', $user);
        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = new SlimResponse();
        $payload  = json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}