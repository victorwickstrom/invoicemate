<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Skapa en manuell verifikation (draft)
    $app->post('/{organizationId}/vouchers/manuel', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        $guid = uniqid();
        $voucherDate = $data['voucherDate'] ?? null;
        $fileGuid = $data['fileGuid'] ?? null;
        $externalReference = $data['externalReference'] ?? null;
        $status = 'Draft';
        $timestamp = time();

        $stmt = $pdo->prepare("
            INSERT INTO manual_voucher (guid, organization_id, voucher_date, file_guid, external_reference, status, timestamp)
            VALUES (:guid, :organizationId, :voucherDate, :fileGuid, :externalReference, :status, :timestamp)
        ");
        $stmt->execute([
            ':guid' => $guid,
            ':organizationId' => $organizationId,
            ':voucherDate' => $voucherDate,
            ':fileGuid' => $fileGuid,
            ':externalReference' => $externalReference,
            ':status' => $status,
            ':timestamp' => $timestamp
        ]);

        return $response->withStatus(200)->withJson(["guid" => $guid, "status" => $status, "timestamp" => $timestamp]);
    });

    // Hämta en specifik manuell verifikation
    $app->get('/{organizationId}/vouchers/manuel/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $guid = $args['guid'];

        $stmt = $pdo->prepare("SELECT * FROM manual_voucher WHERE organization_id = :organizationId AND guid = :guid");
        $stmt->execute([':organizationId' => $organizationId, ':guid' => $guid]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$voucher) {
            return $response->withStatus(404)->withJson(["error" => "Manual voucher not found"]);
        }

        return $response->withStatus(200)->withJson($voucher);
    });

    // Uppdatera en manuell verifikation
    $app->put('/{organizationId}/vouchers/manuel/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $guid = $args['guid'];
        $data = $request->getParsedBody();

        $voucherDate = $data['voucherDate'] ?? null;
        $fileGuid = $data['fileGuid'] ?? null;
        $externalReference = $data['externalReference'] ?? null;
        $timestamp = $data['timestamp'] ?? null;

        if (!$timestamp) {
            return $response->withStatus(400)->withJson(["error" => "Timestamp required for update"]);
        }

        $stmt = $pdo->prepare("
            UPDATE manual_voucher 
            SET voucher_date = :voucherDate, file_guid = :fileGuid, external_reference = :externalReference, timestamp = :timestamp 
            WHERE organization_id = :organizationId AND guid = :guid
        ");
        $stmt->execute([
            ':voucherDate' => $voucherDate,
            ':fileGuid' => $fileGuid,
            ':externalReference' => $externalReference,
            ':timestamp' => time(),
            ':organizationId' => $organizationId,
            ':guid' => $guid
        ]);

        return $response->withStatus(200)->withJson(["message" => "Manual voucher updated successfully", "timestamp" => time()]);
    });

    // Bokföra en manuell verifikation
    $app->post('/{organizationId}/vouchers/manuel/{guid}/book', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $guid = $args['guid'];
        $data = $request->getParsedBody();

        $timestamp = $data['timestamp'] ?? null;
        if (!$timestamp) {
            return $response->withStatus(400)->withJson(["error" => "Timestamp required for booking"]);
        }

        $stmt = $pdo->prepare("UPDATE manual_voucher SET status = 'Booked', booking_time = :bookingTime WHERE organization_id = :organizationId AND guid = :guid");
        $stmt->execute([
            ':bookingTime' => date("Y-m-d H:i:s"),
            ':organizationId' => $organizationId,
            ':guid' => $guid
        ]);

        return $response->withStatus(200)->withJson(["message" => "Manual voucher booked successfully"]);
    });

    // Ta bort en manuell verifikation
    $app->delete('/{organizationId}/vouchers/manuel/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $guid = $args['guid'];
        $data = $request->getParsedBody();

        $timestamp = $data['timestamp'] ?? null;
        if (!$timestamp) {
            return $response->withStatus(400)->withJson(["error" => "Timestamp required for deletion"]);
        }

        // Om status är "Booked", markera som borttagen
        $stmt = $pdo->prepare("SELECT status FROM manual_voucher WHERE organization_id = :organizationId AND guid = :guid");
        $stmt->execute([':organizationId' => $organizationId, ':guid' => $guid]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$voucher) {
            return $response->withStatus(404)->withJson(["error" => "Manual voucher not found"]);
        }

        if ($voucher['status'] === 'Booked') {
            $stmt = $pdo->prepare("UPDATE manual_voucher SET status = 'Deleted' WHERE organization_id = :organizationId AND guid = :guid");
            $stmt->execute([':organizationId' => $organizationId, ':guid' => $guid]);
            return $response->withStatus(200)->withJson(["message" => "Manual voucher marked as deleted"]);
        }

        // Annars ta bort helt
        $stmt = $pdo->prepare("DELETE FROM manual_voucher WHERE organization_id = :organizationId AND guid = :guid");
        $stmt->execute([':organizationId' => $organizationId, ':guid' => $guid]);

        return $response->withStatus(200)->withJson(["message" => "Manual voucher deleted successfully"]);
    });
};
