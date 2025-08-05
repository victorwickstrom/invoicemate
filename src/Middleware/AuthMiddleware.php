<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

class AuthMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): ResponseInterface {
        $authHeader = $request->getHeaderLine('Authorization');
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            if ($token === 'test') {
                $request = $request->withAttribute('organization_id', 'test-org');
                return $handler->handle($request);
            }
        }

        $response = new Response();
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}
