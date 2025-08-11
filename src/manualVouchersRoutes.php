<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

/**
 * Manual voucher routes.
 *
 * Manual vouchers are free‑form accounting documents that can be drafted
 * containing multiple lines. This implementation stores voucher lines in
 * `manual_voucher_line` so they can be edited prior to booking. Vouchers can
 * be listed, retrieved, updated, booked and deleted. Additionally, an
 * endpoint is provided to list ledger entries generated from various sources.
 */
return function (App $app) {
    $container = $app->getContainer();

    // List manual vouchers for an organization
    $app->get('/{organizationId}/vouchers/manual', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $queryParams = $request->getQueryParams();
        $status = $queryParams['status'] ?? null;
        $sql = "SELECT guid, voucher_date, external_reference, status, voucher_number, booking_time FROM manual_voucher WHERE organization_id = :orgId";
        $params = [':orgId' => $orgId];
        if ($status) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }
        $sql .= " ORDER BY voucher_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($vouchers));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Create a manual voucher draft (with optional lines)
    $app->post('/{organizationId}/vouchers/manual', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $data = $request->getParsedBody();
        $guid = uniqid('manual_', true);
        $voucherDate = $data['voucherDate'] ?? null;
        $fileGuid = $data['fileGuid'] ?? null;
        $externalReference = $data['externalReference'] ?? null;
        $status = 'Draft';
        $timestamp = time();
        // Insert voucher header
        $stmt = $pdo->prepare("INSERT INTO manual_voucher (guid, organization_id, voucher_date, file_guid, external_reference, status, timestamp) VALUES (:guid, :orgId, :voucherDate, :fileGuid, :externalReference, :status, :timestamp)");
        $stmt->execute([
            ':guid' => $guid,
            ':orgId' => $orgId,
            ':voucherDate' => $voucherDate,
            ':fileGuid' => $fileGuid,
            ':externalReference' => $externalReference,
            ':status' => $status,
            ':timestamp' => $timestamp
        ]);
        // Insert lines if provided
        $lines = $data['lines'] ?? [];
        if ($lines && is_array($lines)) {
            $stmtLine = $pdo->prepare("INSERT INTO manual_voucher_line (voucher_guid, account_number, description, amount, vat_code) VALUES (:voucherGuid, :accountNumber, :description, :amount, :vatCode)");
            foreach ($lines as $line) {
                $stmtLine->execute([
                    ':voucherGuid' => $guid,
                    ':accountNumber' => (int) ($line['accountNumber'] ?? 0),
                    ':description' => $line['description'] ?? null,
                    ':amount' => (float) ($line['amount'] ?? 0),
                    ':vatCode' => $line['vatCode'] ?? null
                ]);
            }
        }
        $response->getBody()->write(json_encode(['guid' => $guid, 'status' => $status, 'timestamp' => $timestamp]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Retrieve a manual voucher (with lines)
    $app->get('/{organizationId}/vouchers/manual/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        $stmt = $pdo->prepare("SELECT * FROM manual_voucher WHERE organization_id = :orgId AND guid = :guid");
        $stmt->execute([':orgId' => $orgId, ':guid' => $guid]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$voucher) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Manual voucher not found']));
        }
        $stmtLines = $pdo->prepare("SELECT id, account_number, description, amount, vat_code FROM manual_voucher_line WHERE voucher_guid = :guid");
        $stmtLines->execute([':guid' => $guid]);
        $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
        $voucher['lines'] = $lines;
        $response->getBody()->write(json_encode($voucher));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Update a manual voucher and its lines
    $app->put('/{organizationId}/vouchers/manual/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        $data = $request->getParsedBody();
        // Ensure voucher exists and is draft
        $stmtCheck = $pdo->prepare("SELECT status FROM manual_voucher WHERE organization_id = :orgId AND guid = :guid");
        $stmtCheck->execute([':orgId' => $orgId, ':guid' => $guid]);
        $voucher = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$voucher) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Manual voucher not found']));
        }
        if ($voucher['status'] !== 'Draft') {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Only draft vouchers can be updated']));
        }
        // Update header
        $stmt = $pdo->prepare("UPDATE manual_voucher SET voucher_date = :voucherDate, file_guid = :fileGuid, external_reference = :externalReference, timestamp = :timestamp WHERE organization_id = :orgId AND guid = :guid");
        $stmt->execute([
            ':voucherDate' => $data['voucherDate'] ?? null,
            ':fileGuid' => $data['fileGuid'] ?? null,
            ':externalReference' => $data['externalReference'] ?? null,
            ':timestamp' => time(),
            ':orgId' => $orgId,
            ':guid' => $guid
        ]);
        // Replace lines
        $pdo->prepare("DELETE FROM manual_voucher_line WHERE voucher_guid = :guid")->execute([':guid' => $guid]);
        $lines = $data['lines'] ?? [];
        if ($lines && is_array($lines)) {
            $stmtLine = $pdo->prepare("INSERT INTO manual_voucher_line (voucher_guid, account_number, description, amount, vat_code) VALUES (:voucherGuid, :accountNumber, :description, :amount, :vatCode)");
            foreach ($lines as $line) {
                $stmtLine->execute([
                    ':voucherGuid' => $guid,
                    ':accountNumber' => (int) ($line['accountNumber'] ?? 0),
                    ':description' => $line['description'] ?? null,
                    ':amount' => (float) ($line['amount'] ?? 0),
                    ':vatCode' => $line['vatCode'] ?? null
                ]);
            }
        }
        $response->getBody()->write(json_encode(['message' => 'Manual voucher updated successfully', 'timestamp' => time()]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Book a manual voucher
    $app->post('/{organizationId}/vouchers/manual/{guid}/book', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        $data = $request->getParsedBody();
        $user = $request->getAttribute('user');
        $roles = $user['roles'] ?? [];
        if (!in_array('admin', $roles)) {
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Forbidden: insufficient role']));
        }
        // Fetch voucher
        $stmt = $pdo->prepare("SELECT voucher_date, voucher_number, status FROM manual_voucher WHERE organization_id = :orgId AND guid = :guid");
        $stmt->execute([':orgId' => $orgId, ':guid' => $guid]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$voucher) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Manual voucher not found']));
        }
        // Validate balancing of lines
        $stmtLines = $pdo->prepare("SELECT amount FROM manual_voucher_line WHERE voucher_guid = :guid");
        $stmtLines->execute([':guid' => $guid]);
        $sum = 0.0;
        foreach ($stmtLines->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $sum += (float) $line['amount'];
        }
        if (abs($sum) > 0.001) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Voucher is not balanced. Sum of amounts must be zero.', 'sum' => $sum]));
        }
        // Period lock check
        $voucherDate = $voucher['voucher_date'] ?? null;
        if ($voucherDate) {
            // Ensure is_locked column exists
            $columns = [];
            $infoRows = $pdo->query("PRAGMA table_info(accounting_year)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($infoRows as $col) { $columns[] = $col['name']; }
            if (!in_array('is_locked', $columns)) {
                $pdo->exec('ALTER TABLE accounting_year ADD COLUMN is_locked INTEGER DEFAULT 0');
            }
            $stmtLocked = $pdo->prepare("SELECT COUNT(*) FROM accounting_year WHERE organization_id = :orgId AND is_locked = 1 AND from_date <= :date AND to_date >= :date");
            $stmtLocked->execute([':orgId' => $orgId, ':date' => $voucherDate]);
            if ((int) $stmtLocked->fetchColumn() > 0) {
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => 'Accounting period is locked for date ' . $voucherDate]));
            }
        }
        // Determine next voucher number if not set
        $voucherNumber = $voucher['voucher_number'];
        if (!$voucherNumber) {
            $stmtNum = $pdo->prepare("SELECT COALESCE(MAX(voucher_number), 0) + 1 AS next_number FROM manual_voucher WHERE organization_id = :orgId");
            $stmtNum->execute([':orgId' => $orgId]);
            $voucherNumber = (int) $stmtNum->fetchColumn();
        }
        // Begin transaction: update voucher and insert entries
        $pdo->beginTransaction();
        try {
            // Update voucher
            $stmtUpd = $pdo->prepare("UPDATE manual_voucher SET status = 'Booked', booking_time = :bookingTime, voucher_number = :voucherNumber WHERE organization_id = :orgId AND guid = :guid");
            $stmtUpd->execute([
                ':bookingTime' => date('Y-m-d H:i:s'),
                ':voucherNumber' => $voucherNumber,
                ':orgId' => $orgId,
                ':guid' => $guid
            ]);
            // Insert ledger entries from lines
            $stmtLines = $pdo->prepare("SELECT account_number, description, amount FROM manual_voucher_line WHERE voucher_guid = :guid");
            $stmtLines->execute([':guid' => $guid]);
            $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
            foreach ($lines as $line) {
                $entryGuid = uniqid('entry_', true);
                $stmtEntry = $pdo->prepare("INSERT INTO entries (organization_id, account_number, account_name, entry_date, voucher_number, voucher_type, description, vat_type, vat_code, amount, entry_guid, contact_guid, entry_type) VALUES (:orgId, :accountNumber, NULL, :entryDate, :voucherNumber, 'ManualVoucher', :description, NULL, NULL, :amount, :entryGuid, NULL, 'Normal')");
                $stmtEntry->execute([
                    ':orgId' => $orgId,
                    ':accountNumber' => (int) $line['account_number'],
                    ':entryDate' => date('Y-m-d'),
                    ':voucherNumber' => $voucherNumber,
                    ':description' => $line['description'] ?? '',
                    ':amount' => (float) $line['amount'],
                    ':entryGuid' => $entryGuid
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Booking failed', 'details' => $e->getMessage()]));
        }
        // Audit log
        $userId = $user['user_id'] ?? null;
        $pdo->prepare("INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data) VALUES (:orgId, :userId, 'manual_voucher', :recordId, 'BOOK', :changedData)")
            ->execute([':orgId' => $orgId, ':userId' => $userId, ':recordId' => $guid, ':changedData' => json_encode($lines)]);
        $response->getBody()->write(json_encode(['message' => 'Manual voucher booked successfully', 'voucherNumber' => $voucherNumber]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Delete a manual voucher
    $app->delete('/{organizationId}/vouchers/manual/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        $data = $request->getParsedBody();
        $timestamp = $data['timestamp'] ?? null;
        if (!$timestamp) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Timestamp required for deletion']));
        }
        // Check status
        $stmt = $pdo->prepare("SELECT status FROM manual_voucher WHERE organization_id = :orgId AND guid = :guid");
        $stmt->execute([':orgId' => $orgId, ':guid' => $guid]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$voucher) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Manual voucher not found']));
        }
        if ($voucher['status'] === 'Booked') {
            // Soft delete
            $pdo->prepare("UPDATE manual_voucher SET status = 'Deleted' WHERE organization_id = :orgId AND guid = :guid")
                ->execute([':orgId' => $orgId, ':guid' => $guid]);
            return $response->getBody()->write(json_encode(['message' => 'Manual voucher marked as deleted']));
        }
        // Delete header and lines
        $pdo->prepare("DELETE FROM manual_voucher_line WHERE voucher_guid = :guid")->execute([':guid' => $guid]);
        $pdo->prepare("DELETE FROM manual_voucher WHERE organization_id = :orgId AND guid = :guid")->execute([':orgId' => $orgId, ':guid' => $guid]);
        $response->getBody()->write(json_encode(['message' => 'Manual voucher deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // List ledger entries with optional filters (date range, account)
    $app->get('/{organizationId}/entries', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $query = $request->getQueryParams();
        $from = $query['from'] ?? null;
        $to = $query['to'] ?? null;
        $accountNumber = isset($query['accountNumber']) ? (int)$query['accountNumber'] : null;
        $sql = "SELECT * FROM entries WHERE organization_id = :orgId";
        $params = [':orgId' => $orgId];
        if ($from) {
            $sql .= " AND entry_date >= :from";
            $params[':from'] = $from;
        }
        if ($to) {
            $sql .= " AND entry_date <= :to";
            $params[':to'] = $to;
        }
        if ($accountNumber) {
            $sql .= " AND account_number = :accNum";
            $params[':accNum'] = $accountNumber;
        }
        $sql .= " ORDER BY entry_date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($entries));
        return $response->withHeader('Content-Type', 'application/json');
    });
};