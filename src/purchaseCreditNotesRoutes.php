<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Hämta alla purchase credit notes för en organisation
    $app->get('/organizations/{organizationId}/vouchers/purchase/creditnotes', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];

        $sql = "SELECT * FROM purchase_credit_note WHERE organization_id = :organizationId ORDER BY date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['organizationId' => $organizationId]);
        $creditNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($creditNotes));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Hämta en specifik purchase credit note
    $app->get('/organizations/{organizationId}/vouchers/purchase/creditnotes/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $guid = $args['guid'];

        $sql = "SELECT * FROM purchase_credit_note WHERE organization_id = :organizationId AND guid = :guid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['organizationId' => $organizationId, 'guid' => $guid]);
        $creditNote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$creditNote) {
            return $response->withStatus(404)->withJson(["error" => "Purchase credit note not found"]);
        }

        $response->getBody()->write(json_encode($creditNote));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Skapa en ny purchase credit note
    $app->post('/organizations/{organizationId}/vouchers/purchase/creditnotes', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        // Validera att obligatoriska fält finns
        if (!isset($data['lines']) || !is_array($data['lines']) || empty($data['lines'])) {
            return $response->withStatus(400)->withJson(["error" => "At least one credit note line is required"]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO purchase_credit_note (
                guid, organization_id, credit_note_for, file_guid, contact_guid, 
                date, currency, timestamp, status, booked_by_type, booked_by_username, booking_time
            ) VALUES (
                :guid, :organization_id, :credit_note_for, :file_guid, :contact_guid, 
                :date, :currency, CURRENT_TIMESTAMP, 'Draft', NULL, NULL, NULL
            )
        ");

        $guid = $data['guid'] ?? uniqid();
        $stmt->execute([
            ':guid' => $guid,
            ':organization_id' => $organizationId,
            ':credit_note_for' => $data['creditNoteFor'] ?? null,
            ':file_guid' => $data['fileGuid'] ?? null,
            ':contact_guid' => $data['contactGuid'] ?? null,
            ':date' => $data['date'] ?? date('Y-m-d'),
            ':currency' => $data['currency'] ?? 'DKK',
        ]);

        // Lägg till credit note lines
        foreach ($data['lines'] as $line) {
            $stmt = $pdo->prepare("
                INSERT INTO purchase_credit_note_line (
                    credit_note_guid, description, quantity, unit, account_number, 
                    base_amount_value, base_amount_value_incl_vat, total_amount, total_amount_incl_vat, vat_code
                ) VALUES (
                    :credit_note_guid, :description, :quantity, :unit, :account_number, 
                    :base_amount_value, :base_amount_value_incl_vat, :total_amount, :total_amount_incl_vat, :vat_code
                )
            ");
            $stmt->execute([
                ':credit_note_guid' => $guid,
                ':description' => $line['description'],
                ':quantity' => $line['quantity'],
                ':unit' => $line['unit'],
                ':account_number' => $line['account_number'],
                ':base_amount_value' => $line['base_amount_value'],
                ':base_amount_value_incl_vat' => $line['base_amount_value_incl_vat'],
                ':total_amount' => $line['total_amount'],
                ':total_amount_incl_vat' => $line['total_amount_incl_vat'],
                ':vat_code' => $line['vat_code'] ?? null,
            ]);
        }

        return $response->withStatus(201)->withJson(["message" => "Purchase credit note created successfully", "guid" => $guid]);
    });

    // Uppdatera en purchase credit note
    $app->put('/organizations/{organizationId}/vouchers/purchase/creditnotes/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $guid = $args['guid'];
        $data = $request->getParsedBody();

        $allowedFields = ['credit_note_for', 'file_guid', 'contact_guid', 'date', 'currency'];
        $updateFields = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateFields)) {
            return $response->withStatus(400)->withJson(["error" => "No valid fields provided for update"]);
        }

        $setPart = implode(", ", array_map(fn($key) => "$key = :$key", array_keys($updateFields)));
        $sql = "UPDATE purchase_credit_note SET $setPart, timestamp = CURRENT_TIMESTAMP WHERE organization_id = :organizationId AND guid = :guid";
        $stmt = $pdo->prepare($sql);

        $updateFields['organizationId'] = $organizationId;
        $updateFields['guid'] = $guid;

        if (!$stmt->execute($updateFields)) {
            return $response->withStatus(500)->withJson(["error" => "Failed to update purchase credit note"]);
        }

        return $response->withStatus(200)->withJson(["message" => "Purchase credit note updated successfully"]);
    });

    // Boka en purchase credit note
    $app->post('/organizations/{organizationId}/vouchers/purchase/creditnotes/{guid}/book', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $guid = $args['guid'];

        $stmt = $pdo->prepare("UPDATE purchase_credit_note SET status = 'Booked', booking_time = CURRENT_TIMESTAMP WHERE organization_id = :organizationId AND guid = :guid");
        $stmt->execute(['organizationId' => $organizationId, 'guid' => $guid]);

        return $response->withStatus(200)->withJson(["message" => "Purchase credit note booked successfully"]);
    });

    // Ta bort en purchase credit note
    $app->delete('/organizations/{organizationId}/vouchers/purchase/creditnotes/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $guid = $args['guid'];

        $stmt = $pdo->prepare("DELETE FROM purchase_credit_note WHERE organization_id = :organizationId AND guid = :guid");
        $stmt->execute(['organizationId' => $organizationId, 'guid' => $guid]);

        return $response->withStatus(200)->withJson(["message" => "Purchase credit note deleted successfully"]);
    });
};
