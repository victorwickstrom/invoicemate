<?php
namespace Invoicemate\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Middleware that ensures the authenticated user belongs to the organization
 * specified in the route parameter {organizationId}.
 */
class OrgGuardMiddleware implements MiddlewareInterface
{
    private ResponseFactory $responseFactory;

    public function __construct(ResponseFactory $responseFactory)
    {
        $this->responseFactory = $responseFactory;
    }

    public function process(Request $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $claims = $request->getAttribute('user');
        if (!is_array($claims) || !isset($claims['organization_id'])) {
            return $this->forbidden('Missing user claims');
        }
        $orgId = $request->getAttribute('organizationId');
        // The attribute may not be set; fall back to route parameter
        if ($orgId === null && $request->getAttribute('route')) {
            $route = $request->getAttribute('route');
            $orgId = $route->getArgument('organizationId');
        }
        if ((string)$claims['organization_id'] !== (string)$orgId) {
            return $this->forbidden('Organization mismatch');
        }
        return $handler->handle($request);
    }

    private function forbidden(string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(403);
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}