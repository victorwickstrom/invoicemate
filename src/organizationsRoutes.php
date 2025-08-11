<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

/**
 * Organisation management routes.
 *
 * This file exposes endpoints for reading, creating and updating
 * organisations.  The top-level resource is scoped by organisation id
 * under the `/v1` prefix.  Administrators may also create new organisations
 * via POST `/v1/organizations`.  Sensitive flags such as `is_vat_free` are
 * exposed and can be modified through the update endpoint.
 */
return function (App $app) {
    /** @var ContainerInterface $container */
    $container = $app->getContainer();

    // Retrieve a single organisation by its id
    $app->get('/v1/{organizationId}', function (Request $request, Response $response, array $args) use ($container) {
        /** @var \PDO $pdo */
        $pdo = $container->get('db');
        $orgId = (int) $args['organizationId'];
        $stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = :id');
        $stmt->execute([':id' => $orgId]);
        $org = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$org) {
            $response->getBody()->write(json_encode(['error' => 'Organization not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode($org));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Update an organisation.  The caller must be an administrator for this organisation.
    $app->put('/v1/{organizationId}', function (Request $request, Response $response, array $args) use ($container) {
        /** @var \PDO $pdo */
        $pdo = $container->get('db');
        $orgId = (int) $args['organizationId'];
        // Ensure the requester is admin
        $user = $request->getAttribute('user');
        $roles = $user['roles'] ?? [];
        if (!in_array('admin', $roles, true)) {
            $response->getBody()->write(json_encode(['error' => 'Forbidden: only admin may update organisation']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
        $data = json_decode($request->getBody()->getContents(), true);
        // Define allowed fields for update
        $allowedFields = [
            'name', 'type', 'ispro', 'ispayingpro', 'is_vat_free', 'email', 'phone', 'street', 'city', 'zipcode', 'attperson', 'istaxfreeunion', 'vatnumber', 'countrykey', 'website'
        ];
        $updateFields = array_intersect_key($data ?? [], array_flip($allowedFields));
        if (empty($updateFields)) {
            $response->getBody()->write(json_encode(['error' => 'No valid fields provided for update']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        $setPart = implode(', ', array_map(static fn($key) => "$key = :$key", array_keys($updateFields)));
        $updateFields['id'] = $orgId;
        $sql = "UPDATE organizations SET $setPart WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($updateFields)) {
            $response->getBody()->write(json_encode(['error' => 'Failed to update organisation']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode(['message' => 'Organization updated successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Create a new organisation.  This should normally only be allowed for a
    // system-level administrator; therefore this endpoint is not grouped by
    // organisation id.  It initialises a first accounting year and could
    // optionally associate an initial admin user.  For now it simply
    // inserts a row into the organisations table.
    $app->post('/v1/organizations', function (Request $request, Response $response) use ($container) {
        /** @var \PDO $pdo */
        $pdo = $container->get('db');
        $data = json_decode($request->getBody()->getContents(), true);
        // Basic validation of required fields
        $requiredFields = ['name'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $response->getBody()->write(json_encode(['error' => "Missing required field: $field"]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }
        // Prepare insert
        $stmt = $pdo->prepare("INSERT INTO organizations (name, type, ispro, ispayingpro, is_vat_free, email, phone, street, city, zipcode, attperson, istaxfreeunion, vatnumber, countrykey, website, created_at, updated_at) VALUES (:name, :type, :ispro, :ispayingpro, :is_vat_free, :email, :phone, :street, :city, :zipcode, :attperson, :istaxfreeunion, :vatnumber, :countrykey, :website, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':name'           => $data['name'],
            ':type'           => $data['type'] ?? 'default',
            ':ispro'          => $data['ispro'] ?? 0,
            ':ispayingpro'    => $data['ispayingpro'] ?? 0,
            ':is_vat_free'    => $data['is_vat_free'] ?? ($data['isvatfree'] ?? 0),
            ':email'          => $data['email'] ?? null,
            ':phone'          => $data['phone'] ?? null,
            ':street'         => $data['street'] ?? null,
            ':city'           => $data['city'] ?? null,
            ':zipcode'        => $data['zipcode'] ?? null,
            ':attperson'      => $data['attperson'] ?? null,
            ':istaxfreeunion' => $data['istaxfreeunion'] ?? 0,
            ':vatnumber'      => $data['vatnumber'] ?? null,
            ':countrykey'     => $data['countrykey'] ?? null,
            ':website'        => $data['website'] ?? null
        ]);
        $newId = (int)$pdo->lastInsertId();
        // TODO: initialise first accounting year and admin user here
        $response->getBody()->write(json_encode(['id' => $newId, 'message' => 'Organization created successfully']));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    });
};