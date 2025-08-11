<?php
namespace Invoicemate\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Middleware enforcing that the authenticated user has one of the given roles.
 */
class RoleMiddleware implements MiddlewareInterface
{
    private array $roles;
    private ResponseFactory $responseFactory;

    public function __construct(array $roles, ResponseFactory $responseFactory)
    {
        $this->roles = $roles;
        $this->responseFactory = $responseFactory;
    }

    public function process(Request $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $claims = $request->getAttribute('user');
        if (!is_array($claims) || !isset($claims['roles'])) {
            return $this->unauthorized('Missing roles');
        }
        $userRoles = array_map('trim', explode(',', $claims['roles']));
        foreach ($this->roles as $role) {
            if (in_array($role, $userRoles, true)) {
                return $handler->handle($request);
            }
        }
        return $this->unauthorized('Insufficient permissions');
    }

    private function unauthorized(string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(403);
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}