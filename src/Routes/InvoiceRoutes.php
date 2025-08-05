<?php
declare(strict_types=1);

namespace App\Routes;

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\InvoiceService;
use App\Validators\InvoiceValidator;

/**
 * InvoiceRoutes registers all invoice‑related endpoints under a common
 * namespace.  The routing logic is separated from business logic which
 * resides in App\Services\InvoiceService.  Validation is performed via
 * InvoiceValidator before attempting to persist data.
 */
return function (App $app): void {
    $container = $app->getContainer();

    $app->group('/v1/{organizationId}/invoices', function (RouteCollectorProxy $group) use ($container) {
        // GET /v1/{organizationId}/invoices
        $group->get('', function (Request $request, Response $response, array $args) use ($container) {
            $service        = $container->get(InvoiceService::class);
            $organizationId = (int) $args['organizationId'];
            $invoices       = $service->listInvoices($organizationId, $request->getQueryParams());
            $response->getBody()->write(json_encode($invoices, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // POST /v1/{organizationId}/invoices
        $group->post('', function (Request $request, Response $response, array $args) use ($container) {
            $validator      = $container->get(InvoiceValidator::class);
            $service        = $container->get(InvoiceService::class);
            $organizationId = (int) $args['organizationId'];
            $data           = json_decode($request->getBody()->getContents(), true) ?? [];
            $errors         = $validator->validate($data);
            if (!empty($errors)) {
                $response->getBody()->write(json_encode(['errors' => $errors], JSON_UNESCAPED_UNICODE));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }
            $guid = $service->createInvoice($organizationId, $data);
            $response->getBody()->write(json_encode(['guid' => $guid], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        });
    })->add($container->get('auth'));
};