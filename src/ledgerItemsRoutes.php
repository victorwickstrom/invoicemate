<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Hämta ej bokförda verifikat (ledger items)
    $app->get('/{organizationId}/ledgeritems/ledgers', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];

        $stmt = $pdo->prepare("SELECT * FROM ledger_item_lines WHERE id NOT IN (SELECT id FROM ledger_item_lines WHERE is_payment_for_voucher_id IS NOT NULL)");
        $stmt->execute();
        $ledgerItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($ledgerItems));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lägg till nya verifikat (ledger items)
    $app->post('/{organizationId}/ledgeritems', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        if (!is_array($data) || empty($data)) {
            return $response->withStatus(400)->withJson(["error" => "Invalid input, array expected"]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO ledger_item_lines (id, description, amount, account_number, account_vat_code, balancing_account_number, balancing_account_vat_code, is_payment_for_voucher_id)
            VALUES (:id, :description, :amount, :accountNumber, :accountVatCode, :balancingAccountNumber, :balancingAccountVatCode, :isPaymentForVoucherId)
        ");

        foreach ($data as $item) {
            $stmt->execute([
                ':id' => $item['id'] ?? uniqid(),
                ':description' => $item['description'] ?? null,
                ':amount' => $item['amount'] ?? 0,
                ':accountNumber' => $item['accountNumber'] ?? null,
                ':accountVatCode' => $item['accountVatCode'] ?? null,
                ':balancingAccountNumber' => $item['balancingAccountNumber'] ?? null,
                ':balancingAccountVatCode' => $item['balancingAccountVatCode'] ?? null,
                ':isPaymentForVoucherId' => $item['isPaymentForVoucherId'] ?? null
            ]);
        }

        return $response->withStatus(200)->withJson(["message" => "Ledger items added successfully"]);
    });

    // Uppdatera befintliga verifikat
    $app->put('/{organizationId}/ledgeritems', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        if (!is_array($data) || empty($data)) {
            return $response->withStatus(400)->withJson(["error" => "Invalid input, array expected"]);
        }

        $stmt = $pdo->prepare("
            UPDATE ledger_item_lines SET 
                description = :description,
                amount = :amount,
                account_number = :accountNumber,
                account_vat_code = :accountVatCode,
                balancing_account_number = :balancingAccountNumber,
                balancing_account_vat_code = :balancingAccountVatCode,
                is_payment_for_voucher_id = :isPaymentForVoucherId
            WHERE id = :id
        ");

        foreach ($data as $item) {
            if (!isset($item['id'])) {
                return $response->withStatus(400)->withJson(["error" => "Missing 'id' for ledger item update"]);
            }

            $stmt->execute([
                ':id' => $item['id'],
                ':description' => $item['description'] ?? null,
                ':amount' => $item['amount'] ?? 0,
                ':accountNumber' => $item['accountNumber'] ?? null,
                ':accountVatCode' => $item['accountVatCode'] ?? null,
                ':balancingAccountNumber' => $item['balancingAccountNumber'] ?? null,
                ':balancingAccountVatCode' => $item['balancingAccountVatCode'] ?? null,
                ':isPaymentForVoucherId' => $item['isPaymentForVoucherId'] ?? null
            ]);
        }

        return $response->withStatus(200)->withJson(["message" => "Ledger items updated successfully"]);
    });

    // Bokför verifikat
    $app->post('/{organizationId}/ledgeritems/book', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        if (!is_array($data) || empty($data)) {
            return $response->withStatus(400)->withJson(["error" => "Invalid input, array expected"]);
        }

        // Simulerar bokföring
        foreach ($data as $item) {
            if (!isset($item['id'])) {
                return $response->withStatus(400)->withJson(["error" => "Missing 'id' for booking"]);
            }

            $stmt = $pdo->prepare("UPDATE ledger_item_lines SET is_payment_for_voucher_id = 'Booked' WHERE id = :id");
            $stmt->execute([':id' => $item['id']]);
        }

        return $response->withStatus(200)->withJson(["message" => "Ledger items booked successfully"]);
    });

    // Hämta status för bokförda verifikat
    $app->post('/{organizationId}/ledgeritems/status', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        if (!is_array($data) || empty($data)) {
            return $response->withStatus(400)->withJson(["error" => "Invalid input, array expected"]);
        }

        $result = [];
        foreach ($data as $item) {
            $stmt = $pdo->prepare("SELECT id, is_payment_for_voucher_id AS status FROM ledger_item_lines WHERE id = :id");
            $stmt->execute([':id' => $item['id']]);
            $ledgerItem = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ledgerItem) {
                $result[] = [
                    "id" => $ledgerItem['id'],
                    "status" => $ledgerItem['status'] === 'Booked' ? "Booked" : "Draft"
                ];
            }
        }

        return $response->withStatus(200)->withJson($result);
    });

    // Ta bort verifikat
    $app->delete('/{organizationId}/ledgeritems/delete', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        if (!is_array($data) || empty($data)) {
            return $response->withStatus(400)->withJson(["error" => "Invalid input, array expected"]);
        }

        foreach ($data as $item) {
            if (!isset($item['id'])) {
                return $response->withStatus(400)->withJson(["error" => "Missing 'id' for deletion"]);
            }

            $stmt = $pdo->prepare("DELETE FROM ledger_item_lines WHERE id = :id");
            $stmt->execute([':id' => $item['id']]);
        }

        return $response->withStatus(200)->withJson(["message" => "Ledger items deleted successfully"]);
    });
};
