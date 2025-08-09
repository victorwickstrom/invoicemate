<?php
declare(strict_types=1);

namespace App\Middleware;

use App\AuthService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * JwtAuthMiddleware inspects the incoming request for a Bearer token,
 * validates it using the AuthService and, on success, attaches the
 * resulting user payload to the request under the attribute key
 * `user`.  If validation fails and the route is not exempted (such as
 * the login or user creation endpoints), a 401 response is returned.
 * Additionally the middleware checks that the organizationId in the
 * token payload matches the {organizationId} route parameter when
 * present, returning a 403 error on mismatch.  Exempted routes
 * (login and user creation) bypass authentication entirely.
 */
class JwtAuthMiddleware implements MiddlewareInterface
{
    /**
     * @var AuthService
     */
    private $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $path   = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());
        // Define a set of paths that do not require authentication.  The
        // login endpoint and user creation endpoint are publicly
        // accessible so that users may obtain a token and administrators
        // may create new accounts.
        $exemptPaths = [
            '/login',
        ];
        // Bypass JWT validation for POST to /v1/{organizationId}/users
        if ($method === 'POST' && preg_match('#^/v1/[^/]+/users$#', $path)) {
            return $handler->handle($request);
        }
        if (in_array($path, $exemptPaths, true)) {
            return $handler->handle($request);
        }
        $authHeader = $request->getHeaderLine('Authorization');
        if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(['error' => 'Missing or invalid Authorization header']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        $token = trim(substr($authHeader, 7));
        $payload = $this->authService->validateToken($token);
        if (!$payload) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(['error' => 'Invalid or expired token']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        // Check organizationId in route
        $route = $request->getAttribute('route');
        if ($route) {
            $args = $route->getArguments();
            if (isset($args['organizationId']) && (string) $args['organizationId'] !== (string) ($payload['organization_id'] ?? '')) {
                $response = new \Slim\Psr7\Response();
                $response->getBody()->write(json_encode(['error' => 'Forbidden: organization mismatch']));
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
            }
        }
        // Attach user details to request
        $request = $request->withAttribute('user', [
            'user_id'         => $payload['user_id'],
            'organization_id' => $payload['organization_id'],
            'roles'           => [$payload['role']],
        ]);
        return $handler->handle($request);
    }
}