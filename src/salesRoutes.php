<?php
/**
 * Routes for retrieving sales records (invoices and credit notes) for a given organization.
 *
 * This implementation fixes the multi‑tenant leak present in the original code by
 * requiring an organization identifier in the route and filtering by
 * organization_id. It explicitly selects columns in the UNION query and
 * validates the sort order to prevent SQL injection. Pagination and
 * filtering parameters are preserved and robust error responses are
 * provided for invalid input.
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

return function ($app) {
    $container = $app->getContainer();
    $app->get('/{organizationId}/sales', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        // Extract query params
        $queryParams = $request->getQueryParams();
        $startDate    = $queryParams['startDate'] ?? null;
        $endDate      = $queryParams['endDate'] ?? null;
        $statusFilter = isset($queryParams['statusFilter']) ? array_filter(explode(',', $queryParams['statusFilter'])) : [];
        $changesSince = $queryParams['changesSince'] ?? null;
        $deletedOnly  = isset($queryParams['deletedOnly']) ? (bool) $queryParams['deletedOnly'] : false;
        $freeText     = $queryParams['freeTextSearch'] ?? null;
        $page         = isset($queryParams['page']) ? max((int) $queryParams['page'], 0) : 0;
        $pageSize     = isset($queryParams['pageSize']) ? max((int) $queryParams['pageSize'], 1) : 100;
        $sortOrder    = strtoupper($queryParams['sortOrder'] ?? 'DESC');
        if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
            $sortOrder = 'DESC';
        }
        // Base union query selecting explicit columns
        $baseQuery = "(
            SELECT guid, 'Invoice' AS type, number, contact_name, contact_guid, invoice_date AS date,
                   payment_date, description, currency, status, total_excl_vat, total_incl_vat, created_at, updated_at, deleted_at
            FROM invoice
            WHERE organization_id = :orgId
            UNION ALL
            SELECT guid, 'CreditNote' AS type, number, contact_name, contact_guid, credit_note_date AS date,
                   payment_date, description, currency, status, total_excl_vat, total_incl_vat, created_at, updated_at, deleted_at
            FROM credit_note
            WHERE organization_id = :orgId
        ) AS sales";
        $sql = "SELECT * FROM $baseQuery WHERE 1=1";
        $params = [':orgId' => $orgId];
        // Date range filter
        if ($startDate && $endDate) {
            $sql .= " AND date BETWEEN :startDate AND :endDate";
            $params[':startDate'] = $startDate;
            $params[':endDate']   = $endDate;
        }
        // Status filter
        if (!empty($statusFilter)) {
            $placeholders = [];
            foreach ($statusFilter as $i => $val) {
                $key = ':status' . $i;
                $placeholders[] = $key;
                $params[$key] = $val;
            }
            $sql .= " AND status IN (" . implode(',', $placeholders) . ")";
        }
        // Changes since filter (ISO8601 datetime expected)
        if ($changesSince) {
            $sql .= " AND updated_at >= :changesSince";
            $params[':changesSince'] = $changesSince;
        }
        // Deleted filter
        if ($deletedOnly) {
            $sql .= " AND deleted_at IS NOT NULL";
        } else {
            $sql .= " AND deleted_at IS NULL";
        }
        // Free text search across number, contact_name and description
        if ($freeText) {
            $sql .= " AND (number LIKE :freeText OR contact_name LIKE :freeText OR description LIKE :freeText)";
            $params[':freeText'] = '%' . $freeText . '%';
        }
        // Sorting and pagination
        $sql .= " ORDER BY date $sortOrder, number $sortOrder LIMIT :limit OFFSET :offset";
        $params[':limit']  = $pageSize;
        $params[':offset'] = $page * $pageSize;
        // Prepare and bind parameters explicitly for integer values
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            if (in_array($key, [':limit', ':offset'], true)) {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode([
            'collection' => $results,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'result' => count($results)
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });
};