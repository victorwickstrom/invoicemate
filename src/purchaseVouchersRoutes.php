<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Hämta en inköpsfaktura baserat på GUID
    $app->get('/v1/{organizationId}/vouchers/purchase/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $voucherGuid = $args['guid'];

        $stmt = $pdo->prepare("SELECT * FROM purchase_voucher WHERE guid = ?");
        $stmt->execute([$voucherGuid]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($voucher));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Skapa en ny inköpsfaktura
    $app->post('/v1/{organizationId}/vouchers/purchase', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("INSERT INTO purchase_voucher 
            (guid, voucher_date, payment_date, status, purchase_type, deposit_account_number, region_key, external_reference, currency_key, exchange_rate, booked_by_type, booked_by_username, booking_time, contact_guid, file_guid, timestamp, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $stmt->execute([
            uniqid(), $data['voucherDate'], $data['paymentDate'] ?? null, 'Draft', $data['purchaseType'], 
            $data['depositAccountNumber'], $data['regionKey'] ?? null, 
            $data['externalReference'] ?? null, $data['currencyKey'] ?? 'DKK', 100, 
            null, null, null, $data['contactGuid'] ?? null, $data['fileGuid'] ?? null, 
            date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')
        ]);

        $response->getBody()->write(json_encode(['message' => 'Purchase voucher created successfully']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Radera en inköpsfaktura
    $app->delete('/v1/{organizationId}/vouchers/purchase/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $voucherGuid = $args['guid'];

        $stmt = $pdo->prepare("DELETE FROM purchase_voucher WHERE guid = ?");
        $stmt->execute([$voucherGuid]);

        $response->getBody()->write(json_encode(['message' => 'Purchase voucher deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Bokföra en inköpsfaktura
    $app->post('/v1/{organizationId}/vouchers/purchase/{guid}/book', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $guid = $args['guid'];
        $data = $request->getParsedBody();

        // Role-based access control: only admin may book vouchers
        $user = $request->getAttribute('user');
        $roles = $user['roles'] ?? [];
        if (!in_array('admin', $roles)) {
            return $response->withStatus(403)
                ->withHeader('Content-Type', 'application/json')
                ->withJson(['error' => 'Forbidden: insufficient role']);
        }

        // Ensure voucher_number column exists
        $columns = [];
        $stmt = $pdo->query("PRAGMA table_info(purchase_voucher)");
        $tableInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tableInfo as $col) {
            $columns[] = $col['name'];
        }
        if (!in_array('voucher_number', $columns)) {
            // Add column if it does not exist
            $pdo->exec('ALTER TABLE purchase_voucher ADD COLUMN voucher_number INTEGER');
        }

        // Fetch current voucher and status
        $stmt = $pdo->prepare("SELECT status, voucher_number, file_guid FROM purchase_voucher WHERE guid = :guid");
        $stmt->execute([':guid' => $guid]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$voucher) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')->withJson(['error' => 'Purchase voucher not found']);
        }
        // Check if period is locked based on voucher_date
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
        // Retrieve voucher_date
        $stmtDate = $pdo->prepare("SELECT voucher_date FROM purchase_voucher WHERE guid = :guid");
        $stmtDate->execute([':guid' => $guid]);
        $voucherDateRow = $stmtDate->fetch(PDO::FETCH_ASSOC);
        $voucherDate = $voucherDateRow['voucher_date'] ?? null;
        if ($voucherDate) {
            $stmtLocked = $pdo->prepare("SELECT COUNT(*) FROM accounting_year WHERE organization_id = :orgId AND is_locked = 1 AND from_date <= :date AND to_date >= :date");
            $stmtLocked->execute([':orgId' => $organizationId, ':date' => $voucherDate]);
            $isLocked = (int) $stmtLocked->fetchColumn() > 0;
            if ($isLocked) {
                return $response->withStatus(400)
                    ->withHeader('Content-Type', 'application/json')
                    ->withJson(['error' => 'Accounting period is locked for date ' . $voucherDate]);
            }
        }
        if ($voucher['status'] === 'Booked') {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')->withJson(['error' => 'Purchase voucher is already booked']);
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
                    ->withHeader('Content-Type', 'application/json')
                    ->withJson(['error' => 'Voucher is not balanced. Sum of amounts must be zero.', 'sum' => $sum]);
            }
        }

        // Determine next voucher number if not already set
        $voucherNumber = $voucher['voucher_number'];
        if (!$voucherNumber) {
            $stmtNext = $pdo->prepare("SELECT COALESCE(MAX(voucher_number), 0) + 1 AS next_number FROM purchase_voucher WHERE voucher_number IS NOT NULL AND voucher_number > 0");
            $stmtNext->execute();
            $voucherNumber = (int) $stmtNext->fetchColumn();
        }

        // Begin transaction
        $pdo->beginTransaction();
        try {
            // Update voucher status and voucher_number
            $stmt = $pdo->prepare("UPDATE purchase_voucher SET status = 'Booked', booking_time = :bookingTime, voucher_number = :voucherNumber WHERE guid = :guid");
            $stmt->execute([
                ':bookingTime' => date('Y-m-d H:i:s'),
                ':voucherNumber' => $voucherNumber,
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
                        ':voucherType' => 'PurchaseVoucher',
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
                ->withHeader('Content-Type', 'application/json')
                ->withJson(['error' => 'Booking failed', 'details' => $e->getMessage()]);
        }

        // Insert audit log before returning
        $user   = $request->getAttribute('user');
        $userId = $user['user_id'] ?? null;
        $logStmt = $pdo->prepare("INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data) VALUES (:orgId, :userId, :tableName, :recordId, :operation, :changedData)");
        $logStmt->execute([
            ':orgId'       => $organizationId ?? null,
            ':userId'      => $userId,
            ':tableName'   => 'purchase_voucher',
            ':recordId'    => $guid,
            ':operation'   => 'BOOK',
            ':changedData' => json_encode($lines),
        ]);

        return $response->withStatus(200)
            ->withHeader('Content-Type', 'application/json')
            ->withJson([
                'message'       => 'Purchase voucher booked successfully',
                'voucherNumber' => $voucherNumber
            ]);
    });
};
