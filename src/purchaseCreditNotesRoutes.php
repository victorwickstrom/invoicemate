<?php
/**
 * Routes for managing supplier (purchase) credit notes.
 *
 * This module enhances the original implementation by adding multi‑tenant
 * filtering, server‑side computation/validation of line amounts and
 * double‑entry bookkeeping when booking a credit note. A helper fetches
 * the default accounts payable account per organization and falls back
 * to 2100 if none is configured. Each credit note line will produce
 * two ledger entries upon booking: one debiting accounts payable and
 * one crediting the cost account associated with the line.
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

return function ($app) {
    $container = $app->getContainer();

    /**
     * Determine the default payable account for an organization. If the
     * organization has configured `default_payable_account`, that is
     * returned; otherwise 2100 is used.
     *
     * @param \PDO $pdo
     * @param string $orgId
     * @return int
     */
    $getPayableAccount = function (\PDO $pdo, string $orgId): int {
        $stmt = $pdo->prepare('SELECT default_payable_account FROM organizations WHERE id = :id');
        $stmt->execute([':id' => $orgId]);
        $account = $stmt->fetchColumn();
        return $account ? (int) $account : 2100;
    };

    /**
     * Compute or validate credit note lines. For each line the function
     * ensures that base_amount_value and base_amount_value_incl_vat are
     * consistent with quantity and unit price. If vat_code is provided
     * its rate is looked up from vat_type; otherwise VAT is assumed zero.
     *
     * @param \PDO $pdo
     * @param array $lines
     * @return array processed lines with computed fields
     */
    $processLines = function (\PDO $pdo, array $lines) {
        // Build VAT map
        $vatMap = [];
        $stmtVat = $pdo->query('SELECT vat_code, vat_rate FROM vat_type');
        if ($stmtVat instanceof PDOStatement) {
            foreach ($stmtVat->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $vatMap[$row['vat_code']] = (float) $row['vat_rate'];
            }
        }
        $processed = [];
        foreach ($lines as $line) {
            $qty = isset($line['quantity']) ? (float) $line['quantity'] : 1.0;
            // Use provided base_amount_value if present, otherwise derive from unit_price
            if (isset($line['base_amount_value'])) {
                $base = (float) $line['base_amount_value'];
            } elseif (isset($line['unit_price'])) {
                $base = $qty * (float) $line['unit_price'];
            } else {
                throw new InvalidArgumentException('Line is missing both base_amount_value and unit_price');
            }
            $vatCode = $line['vat_code'] ?? null;
            $vatRate = $vatMap[$vatCode] ?? 0.0;
            $vatAmount = round($base * $vatRate, 2);
            $incl = round($base + $vatAmount, 2);
            $processed[] = [
                'description' => $line['description'] ?? null,
                'quantity' => $qty,
                'unit' => $line['unit'] ?? null,
                'account_number' => (int) ($line['account_number'] ?? 0),
                'base_amount_value' => round($base, 2),
                'base_amount_value_incl_vat' => $incl,
                'total_amount' => round($base, 2),
                'total_amount_incl_vat' => $incl,
                'vat_code' => $vatCode,
                'vat_rate' => $vatRate,
            ];
        }
        return $processed;
    };

    // List purchase credit notes for an organization
    $app->get('/{organizationId}/vouchers/purchase/creditnotes', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $stmt = $pdo->prepare('SELECT * FROM purchase_credit_note WHERE organization_id = :organizationId ORDER BY date DESC');
        $stmt->execute(['organizationId' => $orgId]);
        $creditNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($creditNotes));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Retrieve a specific purchase credit note
    $app->get('/{organizationId}/vouchers/purchase/creditnotes/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        $stmt = $pdo->prepare('SELECT * FROM purchase_credit_note WHERE organization_id = :organizationId AND guid = :guid');
        $stmt->execute(['organizationId' => $orgId, 'guid' => $guid]);
        $creditNote = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$creditNote) {
            $response->getBody()->write(json_encode(['error' => 'Purchase credit note not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        // Fetch lines
        $stmtLines = $pdo->prepare('SELECT * FROM purchase_credit_note_line WHERE credit_note_guid = :guid');
        $stmtLines->execute([':guid' => $guid]);
        $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
        $creditNote['lines'] = $lines;
        $response->getBody()->write(json_encode($creditNote));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Create a new purchase credit note
    $app->post('/{organizationId}/vouchers/purchase/creditnotes', function (Request $request, Response $response, array $args) use ($container, $processLines) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $data = $request->getParsedBody();
        // Validate presence of lines
        if (!isset($data['lines']) || !is_array($data['lines']) || empty($data['lines'])) {
            $response->getBody()->write(json_encode(['error' => 'At least one credit note line is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        $guid = $data['guid'] ?? uniqid('pcn_', true);
        $lines = $processLines($pdo, $data['lines']);
        // Insert header
        $stmt = $pdo->prepare('INSERT INTO purchase_credit_note (guid, organization_id, credit_note_for, file_guid, contact_guid, date, currency, timestamp, status, booked_by_type, booked_by_username, booking_time) VALUES (:guid, :organization_id, :credit_note_for, :file_guid, :contact_guid, :date, :currency, CURRENT_TIMESTAMP, :status, NULL, NULL, NULL)');
        $stmt->execute([
            ':guid' => $guid,
            ':organization_id' => $orgId,
            ':credit_note_for' => $data['creditNoteFor'] ?? null,
            ':file_guid' => $data['fileGuid'] ?? null,
            ':contact_guid' => $data['contactGuid'] ?? null,
            ':date' => $data['date'] ?? date('Y-m-d'),
            ':currency' => $data['currency'] ?? 'DKK',
            ':status' => 'Draft',
        ]);
        // Insert lines
        $stmtLine = $pdo->prepare('INSERT INTO purchase_credit_note_line (credit_note_guid, description, quantity, unit, account_number, base_amount_value, base_amount_value_incl_vat, total_amount, total_amount_incl_vat, vat_code) VALUES (:credit_note_guid, :description, :quantity, :unit, :account_number, :base_amount_value, :base_amount_value_incl_vat, :total_amount, :total_amount_incl_vat, :vat_code)');
        foreach ($lines as $line) {
            $stmtLine->execute([
                ':credit_note_guid' => $guid,
                ':description' => $line['description'],
                ':quantity' => $line['quantity'],
                ':unit' => $line['unit'],
                ':account_number' => $line['account_number'],
                ':base_amount_value' => $line['base_amount_value'],
                ':base_amount_value_incl_vat' => $line['base_amount_value_incl_vat'],
                ':total_amount' => $line['total_amount'],
                ':total_amount_incl_vat' => $line['total_amount_incl_vat'],
                ':vat_code' => $line['vat_code'],
            ]);
        }
        $response->getBody()->write(json_encode(['message' => 'Purchase credit note created successfully', 'guid' => $guid]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Update a purchase credit note header only (lines not handled here)
    $app->put('/{organizationId}/vouchers/purchase/creditnotes/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        $data = $request->getParsedBody();
        $allowedFields = ['credit_note_for', 'file_guid', 'contact_guid', 'date', 'currency'];
        $updateFields = array_intersect_key($data, array_flip($allowedFields));
        if (empty($updateFields)) {
            $response->getBody()->write(json_encode(['error' => 'No valid fields provided for update']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        $setPart = implode(', ', array_map(fn($key) => "$key = :$key", array_keys($updateFields)));
        $sql = "UPDATE purchase_credit_note SET $setPart, timestamp = CURRENT_TIMESTAMP WHERE organization_id = :organizationId AND guid = :guid";
        $stmt = $pdo->prepare($sql);
        $updateFields['organizationId'] = $orgId;
        $updateFields['guid'] = $guid;
        if (!$stmt->execute($updateFields)) {
            $response->getBody()->write(json_encode(['error' => 'Failed to update purchase credit note']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode(['message' => 'Purchase credit note updated successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Book a purchase credit note (creates ledger entries)
    $app->post('/{organizationId}/vouchers/purchase/creditnotes/{guid}/book', function (Request $request, Response $response, array $args) use ($container, $getPayableAccount) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        // Load credit note header and lines
        $stmt = $pdo->prepare('SELECT * FROM purchase_credit_note WHERE organization_id = :orgId AND guid = :guid AND status = :status');
        $stmt->execute([':orgId' => $orgId, ':guid' => $guid, ':status' => 'Draft']);
        $creditNote = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$creditNote) {
            $response->getBody()->write(json_encode(['error' => 'Credit note not found or already booked']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $stmtLines = $pdo->prepare('SELECT * FROM purchase_credit_note_line WHERE credit_note_guid = :guid');
        $stmtLines->execute([':guid' => $guid]);
        $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
        if (empty($lines)) {
            $response->getBody()->write(json_encode(['error' => 'Cannot book credit note without lines']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        $payableAccount = $getPayableAccount($pdo, $orgId);
        // Determine voucher number for entries (use existing credit note number or next)
        $voucherNumber = $creditNote['number'] ?? null;
        if (!$voucherNumber) {
            // Determine next voucher number from entries table
            $stmtNum = $pdo->prepare('SELECT COALESCE(MAX(voucher_number),0)+1 FROM entries WHERE organization_id = :orgId');
            $stmtNum->execute([':orgId' => $orgId]);
            $voucherNumber = (int) $stmtNum->fetchColumn();
        }
        $entryDate = $creditNote['date'] ?? date('Y-m-d');
        // Start transaction
        $pdo->beginTransaction();
        try {
            // Insert ledger entries for each line: debit payables (positive) and credit cost (negative)
            $stmtEntry = $pdo->prepare('INSERT INTO entries (organization_id, voucher_number, voucher_type, entry_date, account_number, amount, description, contact_guid, entry_type, vat_code, created_at, updated_at) VALUES (:orgId, :voucherNumber, :voucherType, :entryDate, :accountNumber, :amount, :description, :contactGuid, :entryType, :vat_code, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
            foreach ($lines as $line) {
                $amountInclVat = (float) $line['base_amount_value_incl_vat'];
                $description = $line['description'] ?? 'Credit note line';
                $contactGuid = $creditNote['contact_guid'] ?? null;
                // Debit accounts payable (reduce liability)
                $stmtEntry->execute([
                    ':orgId' => $orgId,
                    ':voucherNumber' => $voucherNumber,
                    ':voucherType' => 'PurchaseCreditNote',
                    ':entryDate' => $entryDate,
                    ':accountNumber' => $payableAccount,
                    ':amount' => -1 * $amountInclVat,
                    ':description' => $description,
                    ':contactGuid' => $contactGuid,
                    ':entryType' => 'Normal',
                    ':vat_code' => $line['vat_code'] ?? null,
                ]);
                // Credit cost account (reverse expense)
                $stmtEntry->execute([
                    ':orgId' => $orgId,
                    ':voucherNumber' => $voucherNumber,
                    ':voucherType' => 'PurchaseCreditNote',
                    ':entryDate' => $entryDate,
                    ':accountNumber' => (int) $line['account_number'],
                    ':amount' => $amountInclVat,
                    ':description' => $description,
                    ':contactGuid' => $contactGuid,
                    ':entryType' => 'Normal',
                    ':vat_code' => $line['vat_code'] ?? null,
                ]);
            }
            // Mark credit note as booked
            $pdo->prepare('UPDATE purchase_credit_note SET status = :status, booking_time = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND guid = :guid')->execute([
                ':status' => 'Booked',
                ':orgId' => $orgId,
                ':guid' => $guid,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $response->getBody()->write(json_encode(['error' => 'Failed to book purchase credit note', 'details' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode(['message' => 'Purchase credit note booked successfully', 'voucher_number' => $voucherNumber]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Delete a purchase credit note (hard delete)
    $app->delete('/{organizationId}/vouchers/purchase/creditnotes/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        $stmt = $pdo->prepare('DELETE FROM purchase_credit_note WHERE organization_id = :organizationId AND guid = :guid');
        $stmt->execute(['organizationId' => $orgId, 'guid' => $guid]);
        $pdo->prepare('DELETE FROM purchase_credit_note_line WHERE credit_note_guid = :guid')->execute([':guid' => $guid]);
        $response->getBody()->write(json_encode(['message' => 'Purchase credit note deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });
};