<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();
    
    // 📌 Hämta lista över kreditnotor
    $app->get('/sales/creditnotes', function (Request $request, Response $response) use ($container) {
        $pdo = $container->get('db');

        // Hämta query-parametrar
        $queryParams = $request->getQueryParams();
        $startDate = $queryParams['startDate'] ?? null;
        $endDate = $queryParams['endDate'] ?? null;
        $statusFilter = isset($queryParams['statusFilter']) ? explode(',', $queryParams['statusFilter']) : [];
        $changesSince = $queryParams['changesSince'] ?? null;
        $deletedOnly = isset($queryParams['deletedOnly']) ? (bool)$queryParams['deletedOnly'] : false;
        $freeTextSearch = $queryParams['freeTextSearch'] ?? null;
        $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 0;
        $pageSize = isset($queryParams['pageSize']) ? (int)$queryParams['pageSize'] : 100;
        $sortOrder = $queryParams['sortOrder'] ?? 'DESC';

        // Grundläggande SQL-fråga
        $sql = "SELECT * FROM credit_note WHERE 1=1";
        $params = [];

        if ($startDate && $endDate) {
            $sql .= " AND credit_note_date BETWEEN ? AND ?";
            $params[] = $startDate;
            $params[] = $endDate;
        }

        if (!empty($statusFilter)) {
            $placeholders = implode(',', array_fill(0, count($statusFilter), '?'));
            $sql .= " AND status IN ($placeholders)";
            $params = array_merge($params, $statusFilter);
        }

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

        // Sortering och sidindelning
        $sql .= " ORDER BY credit_note_date $sortOrder LIMIT ? OFFSET ?";
        $params[] = $pageSize;
        $params[] = $page * $pageSize;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $creditNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode(["collection" => $creditNotes, "pagination" => [
            "page" => $page,
            "pageSize" => $pageSize,
            "result" => count($creditNotes)
        ]]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // 📌 Skapa en ny kreditnota
    $app->post('/sales/creditnotes', function (Request $request, Response $response) use ($container) {
        $pdo = $container->get('db');
        $data = $request->getParsedBody();

        $sql = "INSERT INTO credit_note (guid, currency, language, external_reference, description, comment, 
                credit_note_date, address, number, contact_name, contact_guid, show_lines_incl_vat, 
                total_excl_vat, total_vatable_amount, total_incl_vat, total_non_vatable_amount, 
                total_vat, invoice_template_id, status, created_at, updated_at)
                VALUES (:guid, :currency, :language, :external_reference, :description, :comment, 
                        :credit_note_date, :address, :number, :contact_name, :contact_guid, 
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
            'credit_note_date' => $data['date'] ?? date('Y-m-d'),
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

        $response->getBody()->write(json_encode(["message" => "Credit note created successfully"]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // 📌 Uppdatera en kreditnota
    $app->put('/sales/creditnotes/{guid}', function (Request $request, Response $response, $args) use ($container) {
        $pdo = $container->get('db');
        $guid = $args['guid'];
        $data = $request->getParsedBody();

        $sql = "UPDATE credit_note SET 
                    currency = :currency, language = :language, external_reference = :external_reference, 
                    description = :description, comment = :comment, credit_note_date = :credit_note_date, 
                    address = :address, contact_name = :contact_name, contact_guid = :contact_guid, 
                    show_lines_incl_vat = :show_lines_incl_vat, total_excl_vat = :total_excl_vat, 
                    total_vatable_amount = :total_vatable_amount, total_incl_vat = :total_incl_vat, 
                    total_non_vatable_amount = :total_non_vatable_amount, total_vat = :total_vat, 
                    invoice_template_id = :invoice_template_id, updated_at = CURRENT_TIMESTAMP
                WHERE guid = :guid";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($data, ['guid' => $guid]));

        $response->getBody()->write(json_encode(["message" => "Credit note updated successfully"]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // 📌 Ta bort en kreditnota
    $app->delete('/sales/creditnotes/{guid}', function (Request $request, Response $response, $args) use ($container) {
        $pdo = $container->get('db');
        $guid = $args['guid'];

        $sql = "UPDATE credit_note SET deleted_at = CURRENT_TIMESTAMP WHERE guid = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$guid]);

        $response->getBody()->write(json_encode(["message" => "Credit note deleted successfully"]));
        return $response->withHeader('Content-Type', 'application/json');
    });
};
