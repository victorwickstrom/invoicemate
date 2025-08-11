<?php
declare(strict_types=1);

namespace App\Middleware;

use App\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * JwtAuthMiddleware authenticates incoming requests using bearer tokens.
 *
 * It looks for an Authorization header starting with "Bearer" and delegates
 * validation to the injected AuthService.  If the token is valid the
 * decoded claims are attached to the request as the `user` attribute.  It
 * also verifies that the organisation id embedded in the token matches the
 * route parameter {organizationId} if present to prevent cross-org access.
 */
class JwtAuthMiddleware implements MiddlewareInterface
{
    private AuthService $authService;
    private ResponseFactory $responseFactory;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
        $this->responseFactory = new ResponseFactory();
    }

    public function process(Request $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path   = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());
        // Endpoints that do not require authentication
        $exempt = [
            '/login',
        ];
        // Allow user creation without authentication so the first admin can be created
        if ($method === 'POST' && preg_match('#^/v1/[^/]+/users$#', $path)) {
            return $handler->handle($request);
        }
        if (in_array($path, $exempt, true)) {
            return $handler->handle($request);
        }
        $authHeader = $request->getHeaderLine('Authorization');
        if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
            $resp = $this->responseFactory->createResponse(401);
            $resp->getBody()->write(json_encode(['error' => 'Missing or invalid Authorization header']));
            return $resp->withHeader('Content-Type', 'application/json');
        }
        $token = trim(substr($authHeader, 7));
        $payload = $this->authService->validateToken($token);
        if (!$payload) {
            $resp = $this->responseFactory->createResponse(401);
            $resp->getBody()->write(json_encode(['error' => 'Invalid or expired token']));
            return $resp->withHeader('Content-Type', 'application/json');
        }
        // If the route contains an organisationId parameter, ensure it matches the token
        $route = $request->getAttribute('route');
        if ($route) {
            $args = $route->getArguments();
            if (isset($args['organizationId']) && (string)$args['organizationId'] !== (string)($payload['organization_id'] ?? '')) {
                $resp = $this->responseFactory->createResponse(403);
                $resp->getBody()->write(json_encode(['error' => 'Forbidden: organization mismatch']));
                return $resp->withHeader('Content-Type', 'application/json');
            }
        }
        // Attach user claims to request
        $request = $request->withAttribute('user', [
            'user_id'         => $payload['user_id'],
            'organization_id' => $payload['organization_id'],
            'roles'           => [$payload['role']],
        ]);
        return $handler->handle($request);
    }
}