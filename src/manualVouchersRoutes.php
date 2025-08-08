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

        // Role-based access control: only admin may book vouchers
        $user = $request->getAttribute('user');
        $roles = $user['roles'] ?? [];
        if (!in_array('admin', $roles)) {
            return $response->withStatus(403)->withJson(['error' => 'Forbidden: insufficient role']);
        }

        $timestamp = $data['timestamp'] ?? null;
        if (!$timestamp) {
            return $response->withStatus(400)->withJson(["error" => "Timestamp required for booking"]);
        }

        // Fetch current voucher to check existing number and status
        $stmt = $pdo->prepare("SELECT voucher_number, status FROM manual_voucher WHERE organization_id = :organizationId AND guid = :guid");
        $stmt->execute([
            ':organizationId' => $organizationId,
            ':guid' => $guid
        ]);
        $currentVoucher = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$currentVoucher) {
            return $response->withStatus(404)->withJson(['error' => 'Manual voucher not found']);
        }
        // Validate lines for balancing if provided
        $lines = $data['lines'] ?? [];
        if ($lines && is_array($lines)) {
            $sum = 0.0;
            foreach ($lines as $line) {
                $amount = (float) ($line['amount'] ?? 0);
                $sum += $amount;
            }
            if (abs($sum) > 0.001) {
                return $response->withStatus(400)
                    ->withJson([
                        'error' => 'Voucher is not balanced. Sum of amounts must be zero.',
                        'sum' => $sum
                    ]);
            }
        }

        // Check if period is locked
        $voucherDate = $currentVoucher['voucher_date'] ?? null;
        if ($voucherDate) {
            // Ensure is_locked column exists in accounting_year
            $columnsYear = [];
            $stmtInfo = $pdo->query("PRAGMA table_info(accounting_year)");
            $infoRows = $stmtInfo->fetchAll(PDO::FETCH_ASSOC);
            foreach ($infoRows as $col) {
                $columnsYear[] = $col['name'];
            }
            if (!in_array('is_locked', $columnsYear)) {
                $pdo->exec('ALTER TABLE accounting_year ADD COLUMN is_locked INTEGER DEFAULT 0');
            }
            // Check if voucher_date falls within a locked year
            $stmtLocked = $pdo->prepare("SELECT COUNT(*) FROM accounting_year WHERE organization_id = :orgId AND is_locked = 1 AND from_date <= :date AND to_date >= :date");
            $stmtLocked->execute([':orgId' => $organizationId, ':date' => $voucherDate]);
            $isLocked = (int) $stmtLocked->fetchColumn() > 0;
            if ($isLocked) {
                return $response->withStatus(400)->withJson(['error' => 'Accounting period is locked for date ' . $voucherDate]);
            }
        }

        // Determine next voucher number if not already set
        $voucherNumber = $currentVoucher['voucher_number'];
        if (!$voucherNumber) {
            $stmtNext = $pdo->prepare("SELECT COALESCE(MAX(voucher_number), 0) + 1 AS next_number FROM manual_voucher WHERE organization_id = :orgId");
            $stmtNext->execute([':orgId' => $organizationId]);
            $voucherNumber = (int) $stmtNext->fetchColumn();
        }
        // Begin transaction: update voucher and insert entries
        $pdo->beginTransaction();
        try {
            // Update voucher with booking time, number and status
            $stmt = $pdo->prepare("UPDATE manual_voucher SET status = 'Booked', booking_time = :bookingTime, voucher_number = :voucherNumber WHERE organization_id = :organizationId AND guid = :guid");
            $stmt->execute([
                ':bookingTime' => date('Y-m-d H:i:s'),
                ':voucherNumber' => $voucherNumber,
                ':organizationId' => $organizationId,
                ':guid' => $guid
            ]);

            // Insert journal entries if lines provided
            if ($lines && is_array($lines)) {
                foreach ($lines as $line) {
                    $accNumber = (int) ($line['accountNumber'] ?? 0);
                    $description = $line['description'] ?? '';
                    $amount = (float) ($line['amount'] ?? 0);
                    $entryGuid = uniqid('entry_', true);
                    $stmtEntry = $pdo->prepare('INSERT INTO entries (organization_id, account_number, account_name, entry_date, voucher_number, voucher_type, description, vat_type, vat_code, amount, entry_guid, contact_guid, entry_type) VALUES (:orgId, :accountNumber, NULL, :entryDate, :voucherNumber, :voucherType, :description, NULL, NULL, :amount, :entryGuid, NULL, :entryType)');
                    $stmtEntry->execute([
                        ':orgId' => $organizationId,
                        ':accountNumber' => $accNumber,
                        ':entryDate' => date('Y-m-d'),
                        ':voucherNumber' => $voucherNumber,
                        ':voucherType' => 'ManualVoucher',
                        ':description' => $description,
                        ':amount' => $amount,
                        ':entryGuid' => $entryGuid,
                        ':entryType' => 'Normal'
                    ]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return $response->withStatus(500)
                ->withJson([
                    'error' => 'Booking failed',
                    'details' => $e->getMessage()
                ]);
        }

        // Insert audit log before returning
        $user   = $request->getAttribute('user');
        $userId = $user['user_id'] ?? null;
        $logStmt = $pdo->prepare("INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data) VALUES (:orgId, :userId, :tableName, :recordId, :operation, :changedData)");
        $logStmt->execute([
            ':orgId'       => $organizationId,
            ':userId'      => $userId,
            ':tableName'   => 'manual_voucher',
            ':recordId'    => $guid,
            ':operation'   => 'BOOK',
            ':changedData' => json_encode($lines),
        ]);

        return $response->withStatus(200)->withJson([
            'message'       => 'Manual voucher booked successfully',
            'voucherNumber' => $voucherNumber
        ]);
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
