<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Hämta alla räkenskapsår för en organisation
    $app->get('/{organizationId}/accountingyears', function (Request $request, Response $response, array $args) use ($container) {
        if (!$container->has('db')) {
            throw new \RuntimeException("Database connection not found");
        }

        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];

        $stmt = $pdo->prepare("SELECT * FROM accounting_year WHERE organization_id = :organizationId ORDER BY from_date DESC");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->execute();
        $accountingYears = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($accountingYears));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Hämta ett specifikt räkenskapsår
    $app->get('/{organizationId}/accountingyears/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $id = $args['id'];

        $stmt = $pdo->prepare("SELECT * FROM accounting_year WHERE organization_id = :organizationId AND id = :id");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $accountingYear = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$accountingYear) {
            return $response->withStatus(404)->withJson(["error" => "Accounting year not found"]);
        }

        $response->getBody()->write(json_encode($accountingYear));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lägg till ett nytt räkenskapsår
    $app->post('/{organizationId}/accountingyears', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        if (!isset($data['name'], $data['from_date'], $data['to_date'], $data['salary_sum_tax_state_enum'])) {
            return $response->withStatus(400)->withJson(["error" => "Missing required fields"]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO accounting_year (organization_id, name, from_date, to_date, salary_sum_tax_state_enum)
            VALUES (:organizationId, :name, :from_date, :to_date, :salary_sum_tax_state_enum)
        ");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindParam(':from_date', $data['from_date'], PDO::PARAM_STR);
        $stmt->bindParam(':to_date', $data['to_date'], PDO::PARAM_STR);
        $stmt->bindParam(':salary_sum_tax_state_enum', $data['salary_sum_tax_state_enum'], PDO::PARAM_STR);
        $stmt->execute();

        return $response->withStatus(201)->withJson(["message" => "Accounting year created successfully"]);
    });

    // Uppdatera ett räkenskapsår
    $app->put('/{organizationId}/accountingyears/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $id = $args['id'];
        $data = $request->getParsedBody();

        if (!isset($data['name'], $data['from_date'], $data['to_date'], $data['salary_sum_tax_state_enum'])) {
            return $response->withStatus(400)->withJson(["error" => "Missing required fields"]);
        }

        $stmt = $pdo->prepare("
            UPDATE accounting_year 
            SET name = :name, from_date = :from_date, to_date = :to_date, salary_sum_tax_state_enum = :salary_sum_tax_state_enum
            WHERE organization_id = :organizationId AND id = :id
        ");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindParam(':from_date', $data['from_date'], PDO::PARAM_STR);
        $stmt->bindParam(':to_date', $data['to_date'], PDO::PARAM_STR);
        $stmt->bindParam(':salary_sum_tax_state_enum', $data['salary_sum_tax_state_enum'], PDO::PARAM_STR);
        $stmt->execute();

        return $response->withStatus(200)->withJson(["message" => "Accounting year updated successfully"]);
    });

    // Radera ett räkenskapsår
    $app->delete('/{organizationId}/accountingyears/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $id = $args['id'];

        $stmt = $pdo->prepare("DELETE FROM accounting_year WHERE organization_id = :organizationId AND id = :id");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $response->withStatus(200)->withJson(["message" => "Accounting year deleted successfully"]);
    });
};
