<?php
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $container = $app->getContainer();
    $voucherService = $container->get(Invoicemate\Accounting\VoucherService::class);
    $uploadService = $container->get(Invoicemate\Uploads\UploadService::class);
    $pdo = $container->get(PDO::class);
    $authMiddleware = new Invoicemate\Middleware\AuthMiddleware(
        $container->get(Invoicemate\Utils\JWT::class),
        $container->get(Psr\Http\Message\ResponseFactoryInterface::class)
    );
    $orgGuard = new Invoicemate\Middleware\OrgGuardMiddleware(
        $container->get(Psr\Http\Message\ResponseFactoryInterface::class)
    );
    $roleAccountant = new Invoicemate\Middleware\RoleMiddleware(['admin','accountant'], $container->get(Psr\Http\Message\ResponseFactoryInterface::class));
    // Invoice routes group
    $app->group('/v1/{organizationId}/invoices', function (RouteCollectorProxy $group) use ($pdo, $voucherService, $uploadService) {
        // List invoices
        $group->get('', function ($request, $response, $args) use ($pdo) {
            $orgId = (int)$args['organizationId'];
            $stmt = $pdo->prepare('SELECT * FROM invoice WHERE organization_id = :org');
            $stmt->execute([':org' => $orgId]);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response->getBody()->write(json_encode($invoices));
            return $response->withHeader('Content-Type', 'application/json');
        });
        // Create invoice
        $group->post('', function ($request, $response, $args) use ($pdo, $voucherService, $uploadService) {
            $orgId = (int)$args['organizationId'];
            $data = $request->getParsedBody();
            // Extract invoice fields (customer_id, date, items, lines) from $data
            $date = $data['date'] ?? date('Y-m-d');
            $lines = $data['lines'] ?? [];
            // Validate balance via VoucherService
            try {
                $voucherService->ensureBalanced($lines);
            } catch (\RuntimeException $e) {
                $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            // Insert invoice (simplified)
            $stmt = $pdo->prepare('INSERT INTO invoice (organization_id, date) VALUES (:org, :date)');
            $stmt->execute([':org' => $orgId, ':date' => $date]);
            $invoiceId = (int)$pdo->lastInsertId();
            // TODO: Insert lines, attachments etc.
            $response->getBody()->write(json_encode(['id' => $invoiceId]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        });
    })->add($roleAccountant)->add($orgGuard)->add($authMiddleware);
};