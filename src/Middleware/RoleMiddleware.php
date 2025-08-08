<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * RoleMiddleware checks that the authenticated user has at least one of the
 * allowed roles. It expects a previous middleware (e.g., JwtAuthMiddleware)
 * to have attached a `user` attribute on the request containing roles.
 */
class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @var array List of allowed roles
     */
    private $allowedRoles;

    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $user = $request->getAttribute('user');
        $roles = $user['roles'] ?? [];

        // If no roles or no intersection with allowed roles, deny access
        if (empty($roles) || empty(array_intersect($roles, $this->allowedRoles))) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(['error' => 'Forbidden: insufficient role']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}