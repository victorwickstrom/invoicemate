<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Hämta alla entries för en given period
    $app->get('/{organizationId}/entries', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];

        // Hämta query-parametrar
        $queryParams = $request->getQueryParams();
        $fromDate = $queryParams['fromDate'] ?? null;
        $toDate = $queryParams['toDate'] ?? null;
        $includePrimo = isset($queryParams['includePrimo']) ? filter_var($queryParams['includePrimo'], FILTER_VALIDATE_BOOLEAN) : true;

        // Bygg SQL-query
        $sql = "SELECT * FROM entries WHERE organization_id = :organizationId";
        if ($fromDate) {
            $sql .= " AND entry_date >= :fromDate";
        }
        if ($toDate) {
            $sql .= " AND entry_date <= :toDate";
        }
        if (!$includePrimo) {
            $sql .= " AND entry_type != 'Primo'";
        }
        $sql .= " ORDER BY entry_date ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        if ($fromDate) {
            $stmt->bindParam(':fromDate', $fromDate);
        }
        if ($toDate) {
            $stmt->bindParam(':toDate', $toDate);
        }
        $stmt->execute();
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($entries));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Hämta entries som ändrats inom en viss tidsperiod
    $app->get('/{organizationId}/entries/changes', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];

        // Hämta query-parametrar
        $queryParams = $request->getQueryParams();
        $changesFrom = $queryParams['changesFrom'] ?? null;
        $changesTo = $queryParams['changesTo'] ?? null;
        $includePrimo = isset($queryParams['includePrimo']) ? filter_var($queryParams['includePrimo'], FILTER_VALIDATE_BOOLEAN) : true;

        if (!$changesFrom || !$changesTo) {
            return $response->withStatus(400)->withJson(["error" => "Both 'changesFrom' and 'changesTo' are required"]);
        }

        // Se till att tidsperioden är max 31 dagar
        $dateFrom = new DateTime($changesFrom);
        $dateTo = new DateTime($changesTo);
        $interval = $dateFrom->diff($dateTo);
        if ($interval->days > 31) {
            return $response->withStatus(400)->withJson(["error" => "The time range cannot be longer than 31 days"]);
        }

        // Bygg SQL-query
        $sql = "SELECT * FROM entries WHERE organization_id = :organizationId AND created_at BETWEEN :changesFrom AND :changesTo";
        if (!$includePrimo) {
            $sql .= " AND entry_type != 'Primo'";
        }
        $sql .= " ORDER BY created_at ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':changesFrom', $changesFrom);
        $stmt->bindParam(':changesTo', $changesTo);
        $stmt->execute();
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($entries));
        return $response->withHeader('Content-Type', 'application/json');
    });
};
