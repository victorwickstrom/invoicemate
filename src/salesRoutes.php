<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();
    
    // Hämta alla fakturor och kreditnotor (sales)
    $app->get('/sales', function (Request $request, Response $response) use ($container) {
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

        // Grundläggande SQL-fråga för att hämta fakturor och kreditnotor
        $sql = "
            SELECT guid, 'Invoice' AS type, number, contact_name, contact_guid, invoice_date AS date, 
                   payment_date, description, currency, status, total_excl_vat, total_incl_vat, 
                   created_at, updated_at, deleted_at
            FROM invoice
            UNION ALL
            SELECT guid, 'CreditNote' AS type, number, contact_name, contact_guid, credit_note_date AS date, 
                   payment_date, description, currency, status, total_excl_vat, total_incl_vat, 
                   created_at, updated_at, deleted_at
            FROM credit_note
            WHERE 1=1
        ";

        // Lägg till filtrering baserat på API-query-parametrar
        $params = [];
        
        if ($startDate && $endDate) {
            $sql .= " AND date BETWEEN ? AND ?";
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
        $sql .= " ORDER BY date $sortOrder LIMIT ? OFFSET ?";
        $params[] = $pageSize;
        $params[] = $page * $pageSize;

        // Förbered och exekvera SQL
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Returnera JSON-svar
        $response->getBody()->write(json_encode(["collection" => $sales, "pagination" => [
            "page" => $page,
            "pageSize" => $pageSize,
            "result" => count($sales)
        ]]));
        return $response->withHeader('Content-Type', 'application/json');
    });
};
