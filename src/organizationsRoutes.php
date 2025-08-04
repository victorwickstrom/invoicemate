<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Hämta lista över organisationer
    $app->get('/organizations', function (Request $request, Response $response) use ($container) {
        $pdo = $container->get('db');

        $queryParams = $request->getQueryParams();
        $fields = isset($queryParams['fields']) ? explode(',', strtolower($queryParams['fields'])) : ['id', 'name', 'isPro'];

        $validFields = [
            'id', 'name', 'type', 'ispro', 'ispayingpro', 'isvatfree', 
            'email', 'phone', 'street', 'city', 'zipcode', 'attperson', 
            'istaxfreeunion', 'vatnumber', 'countrykey', 'website'
        ];

        $selectedFields = array_intersect($fields, $validFields);
        if (empty($selectedFields)) {
            $selectedFields = ['id', 'name', 'isPro'];
        }

        $sql = "SELECT " . implode(',', $selectedFields) . " FROM organizations";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($organizations));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lägg till en ny organisation
    $app->post('/organizations', function (Request $request, Response $response) use ($container) {
        $pdo = $container->get('db');
        $data = $request->getParsedBody();

        $requiredFields = ['name', 'isvatfree', 'istaxfreeunion']; // Obligatoriska fält
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                return $response->withStatus(400)->withJson(["error" => "Missing required field: $field"]);
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO organizations (name, type, ispro, ispayingpro, isvatfree, email, phone, street, city, zipcode, attperson, istaxfreeunion, vatnumber, countrykey, website)
            VALUES (:name, :type, :isPro, :isPayingPro, :isVatFree, :email, :phone, :street, :city, :zipCode, :attPerson, :isTaxFreeUnion, :vatNumber, :countryKey, :website)
        ");

        $stmt->execute([
            ':name' => $data['name'],
            ':type' => $data['type'] ?? 'default',
            ':isPro' => $data['ispro'] ?? 0,
            ':isPayingPro' => $data['ispayingpro'] ?? 0,
            ':isVatFree' => $data['isvatfree'],
            ':email' => $data['email'] ?? null,
            ':phone' => $data['phone'] ?? null,
            ':street' => $data['street'] ?? null,
            ':city' => $data['city'] ?? null,
            ':zipCode' => $data['zipcode'] ?? null,
            ':attPerson' => $data['attperson'] ?? null,
            ':isTaxFreeUnion' => $data['istaxfreeunion'],
            ':vatNumber' => $data['vatnumber'] ?? null,
            ':countryKey' => $data['countrykey'] ?? null,
            ':website' => $data['website'] ?? null
        ]);

        return $response->withStatus(201)->withJson(["message" => "Organization created successfully"]);
    });

    // Uppdatera en organisation
    $app->put('/organizations/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $id = $args['id'];
        $data = $request->getParsedBody();

        $allowedFields = [
            'name', 'type', 'ispro', 'ispayingpro', 'isvatfree', 
            'email', 'phone', 'street', 'city', 'zipcode', 'attperson', 
            'istaxfreeunion', 'vatnumber', 'countrykey', 'website'
        ];

        $updateFields = array_intersect_key($data, array_flip($allowedFields));
        if (empty($updateFields)) {
            return $response->withStatus(400)->withJson(["error" => "No valid fields provided for update"]);
        }

        $setPart = implode(", ", array_map(fn($key) => "$key = :$key", array_keys($updateFields)));
        $sql = "UPDATE organizations SET $setPart WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $updateFields['id'] = $id;

        if (!$stmt->execute($updateFields)) {
            return $response->withStatus(500)->withJson(["error" => "Failed to update organization"]);
        }

        return $response->withStatus(200)->withJson(["message" => "Organization updated successfully"]);
    });

    // Ta bort en organisation
    $app->delete('/organizations/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $id = $args['id'];

        $stmt = $pdo->prepare("DELETE FROM organizations WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            return $response->withStatus(404)->withJson(["error" => "Organization not found"]);
        }

        return $response->withStatus(200)->withJson(["message" => "Organization deleted successfully"]);
    });
};
