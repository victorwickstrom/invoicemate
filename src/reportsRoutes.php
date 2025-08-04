<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();
    
    // Hämta saldo balansrapport
    $app->get('/v1/{organizationId}/{accountingYear}/reports/saldo', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE organization_id = ? AND accounting_year = ? AND report_type = 'saldo'");
        $stmt->execute([$args['organizationId'], $args['accountingYear']]);
        $report = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($report));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Hämta resultatrapport
    $app->get('/v1/{organizationId}/{accountingYear}/reports/result', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');

        $stmt = $pdo->prepare("SELECT * FROM reports WHERE organization_id = ? AND accounting_year = ? AND report_type = 'result'");
        $stmt->execute([$args['organizationId'], $args['accountingYear']]);
        $report = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($report));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Hämta primo balansrapport (ingående balans)
    $app->get('/v1/{organizationId}/{accountingYear}/reports/primo', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');

        $stmt = $pdo->prepare("SELECT * FROM reports WHERE organization_id = ? AND accounting_year = ? AND report_type = 'primo'");
        $stmt->execute([$args['organizationId'], $args['accountingYear']]);
        $report = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($report));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Hämta balansrapport
    $app->get('/v1/{organizationId}/{accountingYear}/reports/balance', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');

        $stmt = $pdo->prepare("SELECT * FROM reports WHERE organization_id = ? AND accounting_year = ? AND report_type = 'balance'");
        $stmt->execute([$args['organizationId'], $args['accountingYear']]);
        $report = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($report));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lägg till en ny rapportpost
    $app->post('/v1/{organizationId}/{accountingYear}/reports', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("INSERT INTO reports 
            (organization_id, accounting_year, report_type, account_name, account_number, amount, 
            show_zero_account, show_account_no, include_summary_account, include_ledger_entries, show_vat_type) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $args['organizationId'], $args['accountingYear'], $data['report_type'], 
            $data['account_name'], $data['account_number'], $data['amount'],
            $data['show_zero_account'], $data['show_account_no'], 
            $data['include_summary_account'], $data['include_ledger_entries'], 
            $data['show_vat_type']
        ]);

        $response->getBody()->write(json_encode(['message' => 'Report entry added successfully']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Uppdatera en rapportpost
    $app->put('/v1/{organizationId}/{accountingYear}/reports/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("UPDATE reports SET 
            report_type = ?, account_name = ?, account_number = ?, amount = ?, 
            show_zero_account = ?, show_account_no = ?, 
            include_summary_account = ?, include_ledger_entries = ?, show_vat_type = ? 
            WHERE id = ?");

        $stmt->execute([
            $data['report_type'], $data['account_name'], $data['account_number'], $data['amount'],
            $data['show_zero_account'], $data['show_account_no'], 
            $data['include_summary_account'], $data['include_ledger_entries'], 
            $data['show_vat_type'], $args['id']
        ]);

        $response->getBody()->write(json_encode(['message' => 'Report entry updated successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Radera en rapportpost
    $app->delete('/v1/{organizationId}/{accountingYear}/reports/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');

        $stmt = $pdo->prepare("DELETE FROM reports WHERE id = ?");
        $stmt->execute([$args['id']]);

        $response->getBody()->write(json_encode(['message' => 'Report entry deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });
};
