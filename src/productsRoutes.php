<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Hämta lista över produkter
    $app->get('/organizations/{organizationId}/products', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $queryParams = $request->getQueryParams();

        // Möjliga fält att hämta
        $validFields = [
            'product_number', 'name', 'quantity', 'unit', 'account_number',
            'base_amount_value', 'base_amount_value_incl_vat', 'total_amount', 
            'total_amount_incl_vat', 'external_reference', 'created_at', 'updated_at', 'deleted_at'
        ];

        // Hämta valda fält eller standardfält
        $fields = isset($queryParams['fields']) ? explode(',', strtolower($queryParams['fields'])) : ['product_number', 'name', 'product_guid'];
        $selectedFields = array_intersect($fields, $validFields);
        if (empty($selectedFields)) {
            $selectedFields = ['product_number', 'name', 'product_guid'];
        }

        $sql = "SELECT " . implode(',', $selectedFields) . " FROM products WHERE organization_id = :organizationId ORDER BY updated_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['organizationId' => $organizationId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($products));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Hämta en specifik produkt
    $app->get('/organizations/{organizationId}/products/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $guid = $args['guid'];

        $sql = "SELECT * FROM products WHERE organization_id = :organizationId AND product_guid = :guid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['organizationId' => $organizationId, 'guid' => $guid]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            return $response->withStatus(404)->withJson(["error" => "Product not found"]);
        }

        $response->getBody()->write(json_encode($product));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lägg till en ny produkt
    $app->post('/organizations/{organizationId}/products', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        // Obligatoriska fält
        $requiredFields = ['name', 'base_amount_value', 'quantity', 'account_number', 'unit'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                return $response->withStatus(400)->withJson(["error" => "Missing required field: $field"]);
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO products (
                product_guid, organization_id, product_number, name, base_amount_value, 
                base_amount_value_incl_vat, quantity, account_number, unit, external_reference, 
                comment, created_at, updated_at, total_amount, total_amount_incl_vat
            ) VALUES (
                :product_guid, :organization_id, :product_number, :name, :base_amount_value, 
                :base_amount_value_incl_vat, :quantity, :account_number, :unit, :external_reference, 
                :comment, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :total_amount, :total_amount_incl_vat
            )
        ");

        $stmt->execute([
            ':product_guid' => uniqid(), // Generera ett unikt ID
            ':organization_id' => $organizationId,
            ':product_number' => $data['product_number'] ?? null,
            ':name' => $data['name'],
            ':base_amount_value' => $data['base_amount_value'],
            ':base_amount_value_incl_vat' => $data['base_amount_value_incl_vat'] ?? 0,
            ':quantity' => $data['quantity'],
            ':account_number' => $data['account_number'],
            ':unit' => $data['unit'],
            ':external_reference' => $data['external_reference'] ?? null,
            ':comment' => $data['comment'] ?? null,
            ':total_amount' => $data['base_amount_value'] * $data['quantity'],
            ':total_amount_incl_vat' => ($data['base_amount_value_incl_vat'] ?? 0) * $data['quantity']
        ]);

        return $response->withStatus(201)->withJson(["message" => "Product created successfully"]);
    });

    // Uppdatera en produkt
    $app->put('/organizations/{organizationId}/products/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $guid = $args['guid'];
        $data = $request->getParsedBody();

        $allowedFields = [
            'product_number', 'name', 'base_amount_value', 'base_amount_value_incl_vat', 'quantity', 
            'account_number', 'unit', 'external_reference', 'comment'
        ];

        $updateFields = array_intersect_key($data, array_flip($allowedFields));
        if (empty($updateFields)) {
            return $response->withStatus(400)->withJson(["error" => "No valid fields provided for update"]);
        }

        $setPart = implode(", ", array_map(fn($key) => "$key = :$key", array_keys($updateFields)));
        $sql = "UPDATE products SET $setPart, updated_at = CURRENT_TIMESTAMP WHERE organization_id = :organizationId AND product_guid = :guid";
        $stmt = $pdo->prepare($sql);

        $updateFields['organizationId'] = $organizationId;
        $updateFields['guid'] = $guid;

        if (!$stmt->execute($updateFields)) {
            return $response->withStatus(500)->withJson(["error" => "Failed to update product"]);
        }

        return $response->withStatus(200)->withJson(["message" => "Product updated successfully"]);
    });

    // Ta bort en produkt
    $app->delete('/organizations/{organizationId}/products/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $guid = $args['guid'];

        $stmt = $pdo->prepare("UPDATE products SET deleted_at = CURRENT_TIMESTAMP WHERE organization_id = :organizationId AND product_guid = :guid");
        $stmt->execute(['organizationId' => $organizationId, 'guid' => $guid]);

        if ($stmt->rowCount() === 0) {
            return $response->withStatus(404)->withJson(["error" => "Product not found"]);
        }

        return $response->withStatus(200)->withJson(["message" => "Product deleted successfully"]);
    });
};
