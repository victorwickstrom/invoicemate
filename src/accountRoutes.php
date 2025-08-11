<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

/**
 * Account routes.
 *
 * This simplified controller exposes endpoints for listing, creating,
 * retrieving, updating and deleting accounts for an organization. It ensures
 * that account numbers are unique per organization and provides Danish
 * standard accounts on first access. Accounts are stored in the `account`
 * table with a required `organization_id` column to support multi‑tenant use.
 */
return function (App $app) {
    $container = $app->getContainer();

    // Insert Danish standard accounts if none exist
    $insertStandardAccounts = function (PDO $pdo, string $orgId) {
        $standardAccounts = [
            ['accountNumber' => 1000, 'name' => 'Kasse', 'vatCode' => 'I25'],
            ['accountNumber' => 1100, 'name' => 'Debitorer', 'vatCode' => 'I25'],
            ['accountNumber' => 2000, 'name' => 'Immaterielle aktiver', 'vatCode' => 'U25'],
            ['accountNumber' => 4000, 'name' => 'Salg', 'vatCode' => 'U25'],
            ['accountNumber' => 7000, 'name' => 'Omkostninger', 'vatCode' => 'I25']
        ];
        $stmt = $pdo->prepare("INSERT INTO account (organization_id, accountNumber, name, vatCode) VALUES (:orgId, :accountNumber, :name, :vatCode)");
        foreach ($standardAccounts as $acc) {
            $stmt->execute([':orgId' => $orgId, ':accountNumber' => $acc['accountNumber'], ':name' => $acc['name'], ':vatCode' => $acc['vatCode']]);
        }
    };

    // List accounts
    $app->get('/{organizationId}/accounts', function (Request $request, Response $response, array $args) use ($container, $insertStandardAccounts) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $stmt = $pdo->prepare("SELECT * FROM account WHERE organization_id = :orgId ORDER BY accountNumber ASC");
        $stmt->execute([':orgId' => $orgId]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($accounts)) {
            $insertStandardAccounts($pdo, $orgId);
            $stmt->execute([':orgId' => $orgId]);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $response->getBody()->write(json_encode($accounts));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Retrieve a specific account
    $app->get('/{organizationId}/accounts/{accountNumber}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $accNum = (int) $args['accountNumber'];
        $stmt = $pdo->prepare("SELECT * FROM account WHERE organization_id = :orgId AND accountNumber = :accNum");
        $stmt->execute([':orgId' => $orgId, ':accNum' => $accNum]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Account not found']));
        }
        $response->getBody()->write(json_encode($account));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Create a new account
    $app->post('/{organizationId}/accounts', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $data = $request->getParsedBody();
        if (!isset($data['accountNumber'], $data['name'], $data['vatCode'])) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Missing required fields']));
        }
        $accNum = (int) $data['accountNumber'];
        // Check uniqueness
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM account WHERE organization_id = :orgId AND accountNumber = :accNum");
        $stmt->execute([':orgId' => $orgId, ':accNum' => $accNum]);
        if ((int) $stmt->fetchColumn() > 0) {
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Account number already exists']));
        }
        // Validate VAT code exists in vat_type table
        $stmtVat = $pdo->prepare("SELECT COUNT(*) FROM vat_type WHERE vatCode = :vatCode");
        $stmtVat->execute([':vatCode' => $data['vatCode']]);
        if ((int) $stmtVat->fetchColumn() === 0) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Invalid VAT code']));
        }
        $stmtIns = $pdo->prepare("INSERT INTO account (organization_id, accountNumber, name, vatCode) VALUES (:orgId, :accNum, :name, :vatCode)");
        $stmtIns->execute([':orgId' => $orgId, ':accNum' => $accNum, ':name' => $data['name'], ':vatCode' => $data['vatCode']]);
        $response->getBody()->write(json_encode(['message' => 'Account created successfully']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Update an account
    $app->put('/{organizationId}/accounts/{accountNumber}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $accNum = (int) $args['accountNumber'];
        $data = $request->getParsedBody();
        // Validate existence
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM account WHERE organization_id = :orgId AND accountNumber = :accNum");
        $stmtCheck->execute([':orgId' => $orgId, ':accNum' => $accNum]);
        if ((int) $stmtCheck->fetchColumn() === 0) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Account not found']));
        }
        // Optionally update name or vatCode
        $name = $data['name'] ?? null;
        $vatCode = $data['vatCode'] ?? null;
        // Validate VAT code if provided
        if ($vatCode) {
            $stmtVat = $pdo->prepare("SELECT COUNT(*) FROM vat_type WHERE vatCode = :vatCode");
            $stmtVat->execute([':vatCode' => $vatCode]);
            if ((int) $stmtVat->fetchColumn() === 0) {
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => 'Invalid VAT code']));
            }
        }
        $sql = "UPDATE account SET ";
        $params = [];
        if ($name !== null) {
            $sql .= "name = :name";
            $params[':name'] = $name;
        }
        if ($vatCode !== null) {
            if (!empty($params)) { $sql .= ", "; }
            $sql .= "vatCode = :vatCode";
            $params[':vatCode'] = $vatCode;
        }
        $sql .= " WHERE organization_id = :orgId AND accountNumber = :accNum";
        $params[':orgId'] = $orgId;
        $params[':accNum'] = $accNum;
        if (count($params) > 2) {
            $stmtUp = $pdo->prepare($sql);
            $stmtUp->execute($params);
        }
        $response->getBody()->write(json_encode(['message' => 'Account updated successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Delete an account
    $app->delete('/{organizationId}/accounts/{accountNumber}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $accNum = (int) $args['accountNumber'];
        $stmt = $pdo->prepare("DELETE FROM account WHERE organization_id = :orgId AND accountNumber = :accNum");
        $stmt->execute([':orgId' => $orgId, ':accNum' => $accNum]);
        $response->getBody()->write(json_encode(['message' => 'Account deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });
};