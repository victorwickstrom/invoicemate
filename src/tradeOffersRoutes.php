<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // 📌 Hämta lista över offerter
    $app->get('/tradeoffers', function (Request $request, Response $response) use ($container) {
        $pdo = $container->get('db');

        $queryParams = $request->getQueryParams();
        $changesSince = $queryParams['changesSince'] ?? null;
        $deletedOnly = isset($queryParams['deletedOnly']) ? (bool)$queryParams['deletedOnly'] : false;
        $freeTextSearch = $queryParams['freeTextSearch'] ?? null;
        $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 0;
        $pageSize = isset($queryParams['pageSize']) ? (int)$queryParams['pageSize'] : 100;
        $sortOrder = $queryParams['sortOrder'] ?? 'DESC';

        $sql = "SELECT * FROM trade_offer WHERE 1=1";
        $params = [];

        if ($changesSince) {
            $sql .= " AND updated_at >= ?";
            $params[] = $changesSince;
        }

        if ($deletedOnly) {
            $sql .= " AND deleted_at IS NOT NULL";
        } else {
            $sql .= " AND deleted_at IS NULL";
        }

        if ($freeTextSearch) {
            $sql .= " AND (number LIKE ? OR contact_name LIKE ? OR description LIKE ?)";
            $params[] = "%$freeTextSearch%";
            $params[] = "%$freeTextSearch%";
            $params[] = "%$freeTextSearch%";
        }

        $sql .= " ORDER BY offer_date $sortOrder LIMIT ? OFFSET ?";
        $params[] = $pageSize;
        $params[] = $page * $pageSize;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tradeOffers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode(["collection" => $tradeOffers, "pagination" => [
            "page" => $page,
            "pageSize" => $pageSize,
            "result" => count($tradeOffers)
        ]]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // 📌 Skapa en ny offert
    $app->post('/tradeoffers', function (Request $request, Response $response) use ($container) {
        $pdo = $container->get('db');
        $data = $request->getParsedBody();

        $sql = "INSERT INTO trade_offer (guid, currency, language, external_reference, description, comment, 
                offer_date, address, number, contact_name, contact_guid, show_lines_incl_vat, 
                total_excl_vat, total_vatable_amount, total_incl_vat, total_non_vatable_amount, 
                total_vat, invoice_template_id, status, created_at, updated_at)
                VALUES (:guid, :currency, :language, :external_reference, :description, :comment, 
                        :offer_date, :address, :number, :contact_name, :contact_guid, 
                        :show_lines_incl_vat, :total_excl_vat, :total_vatable_amount, :total_incl_vat, 
                        :total_non_vatable_amount, :total_vat, :invoice_template_id, :status, 
                        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'guid' => $data['guid'] ?? uniqid(),
            'currency' => $data['currency'] ?? 'DKK',
            'language' => $data['language'] ?? 'da-DK',
            'external_reference' => $data['externalReference'] ?? null,
            'description' => $data['description'] ?? null,
            'comment' => $data['comment'] ?? null,
            'offer_date' => $data['date'] ?? date('Y-m-d'),
            'address' => $data['address'] ?? null,
            'number' => $data['number'] ?? null,
            'contact_name' => $data['contactName'] ?? null,
            'contact_guid' => $data['contactGuid'] ?? null,
            'show_lines_incl_vat' => $data['showLinesInclVat'] ?? 0,
            'total_excl_vat' => $data['totalExclVat'] ?? 0,
            'total_vatable_amount' => $data['totalVatableAmount'] ?? 0,
            'total_incl_vat' => $data['totalInclVat'] ?? 0,
            'total_non_vatable_amount' => $data['totalNonVatableAmount'] ?? 0,
            'total_vat' => $data['totalVat'] ?? 0,
            'invoice_template_id' => $data['invoiceTemplateId'] ?? null,
            'status' => 'Draft'
        ]);

        $response->getBody()->write(json_encode(["message" => "Trade offer created successfully"]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // 📌 Uppdatera en offert
    $app->put('/tradeoffers/{guid}', function (Request $request, Response $response, $args) use ($container) {
        $pdo = $container->get('db');
        $guid = $args['guid'];
        $data = $request->getParsedBody();

        $sql = "UPDATE trade_offer SET 
                    currency = :currency, language = :language, external_reference = :external_reference, 
                    description = :description, comment = :comment, offer_date = :offer_date, 
                    address = :address, contact_name = :contact_name, contact_guid = :contact_guid, 
                    show_lines_incl_vat = :show_lines_incl_vat, total_excl_vat = :total_excl_vat, 
                    total_vatable_amount = :total_vatable_amount, total_incl_vat = :total_incl_vat, 
                    total_non_vatable_amount = :total_non_vatable_amount, total_vat = :total_vat, 
                    invoice_template_id = :invoice_template_id, updated_at = CURRENT_TIMESTAMP
                WHERE guid = :guid";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($data, ['guid' => $guid]));

        $response->getBody()->write(json_encode(["message" => "Trade offer updated successfully"]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // 📌 Ta bort en offert
    $app->delete('/tradeoffers/{guid}', function (Request $request, Response $response, $args) use ($container) {
        $pdo = $container->get('db');
        $guid = $args['guid'];

        $sql = "UPDATE trade_offer SET deleted_at = CURRENT_TIMESTAMP WHERE guid = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$guid]);

        $response->getBody()->write(json_encode(["message" => "Trade offer deleted successfully"]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // 📌 Hämta en specifik offert
    $app->get('/tradeoffers/{guid}', function (Request $request, Response $response, $args) use ($container) {
        $pdo = $container->get('db');
        $guid = $args['guid'];

        $sql = "SELECT * FROM trade_offer WHERE guid = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$guid]);
        $tradeOffer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tradeOffer) {
            return $response->withStatus(404)->write(json_encode(["error" => "Trade offer not found"]));
        }

        $response->getBody()->write(json_encode($tradeOffer));
        return $response->withHeader('Content-Type', 'application/json');
    });
};
