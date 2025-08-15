<?php
/**
 * Invoice routes.
 *
 * This module defines REST endpoints for working with invoices.  It covers
 * listing, creation, booking, payments, credit note creation, deletion and
 * email sending.  Several improvements over the original implementation have
 * been introduced:
 *
 *  - All database access uses PDO retrieved via the DI container using the
 *    `PDO::class` alias instead of the string `'db'`.
 *  - Sequential invoice numbers are allocated within a database transaction
 *    to avoid collisions when multiple invoices are created concurrently.  A
 *    unique index on `(organization_id, number)` should exist in the schema
 *    to guarantee uniqueness.
 *  - The booking routine posts journal entries using configurable account
 *    numbers.  Accounts receivable and VAT accounts are resolved via helper
 *    functions which look up the organisation’s chart of accounts and VAT
 *    codes rather than relying on hard coded values (e.g. 1100 and 2610).
 *  - Payment processing updates both the `payment_status` and the high‑level
 *    `status` field.  When an invoice is fully paid the status becomes
 *    `Paid`.  Overdue invoices consider reminder fees and interest rates.
 *  - Credit note creation always associates the new credit note with the
 *    organisation by setting the `organization_id` column directly.  The
 *    temporary helper `ensureCreditNoteHasOrgColumn` has been removed; it is
 *    assumed the database schema has been migrated accordingly.
 *
 * While the original code organised all logic inside route closures, this
 * version extracts reusable pieces into helper functions defined below.  The
 * helpers compute totals, persist invoice lines and resolve account numbers.
 */

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

return function (App $app): void {
    /** @var ContainerInterface $container */
    $container = $app->getContainer();

    $app->group('/v1/{organizationId}/invoices', function (RouteCollectorProxy $group) use ($container): void {
        /**
         * List invoices for an organisation.  Supports optional filters via
         * query parameters.  Deleted invoices are excluded by default.
         */
        $group->get('', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = $args['organizationId'];
            $params = $request->getQueryParams();
            $query = "SELECT * FROM invoice WHERE organization_id = :orgId AND deleted_at IS NULL";
            $bindings = ['orgId' => $orgId];
            // Status filter (comma separated list)
            if (!empty($params['status'])) {
                $statuses = array_map('trim', explode(',', (string)$params['status']));
                $placeholders = implode(',', array_fill(0, count($statuses), '?'));
                $query .= " AND status IN (".$placeholders.")";
                $bindings = array_merge($bindings, $statuses);
            }
            // Free text search across number, contact name or description
            if (!empty($params['search'])) {
                $search = '%' . $params['search'] . '%';
                $query .= " AND (number LIKE ? OR contact_name LIKE ? OR description LIKE ?)";
                $bindings[] = $search;
                $bindings[] = $search;
                $bindings[] = $search;
            }
            $query .= " ORDER BY invoice_date DESC, number DESC";
            $stmt = $pdo->prepare($query);
            $stmt->execute($bindings);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response->getBody()->write(json_encode($invoices));
            return $response->withHeader('Content-Type','application/json');
        });

        /**
         * Create a new invoice.  Persists the invoice header together with
         * all invoice lines and calculates totals on the fly.  Uses a
         * transaction to allocate a sequential invoice number safely.
         */
        $group->post('', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo  = $container->get(PDO::class);
            $orgId = $args['organizationId'];
            $data = json_decode($request->getBody()->getContents(), true);
            $lines = $data['productLines'] ?? $data['invoiceLines'] ?? null;
            if (!$lines || !is_array($lines) || count($lines) === 0) {
                $response->getBody()->write(json_encode(['error' => 'productLines/invoiceLines is required and must be a non-empty array']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            // Generate GUID if not supplied
            $guid = $data['guid'] ?? Uuid::uuid4()->toString();
            // Compute totals up front
            $totals = computeInvoiceTotals($pdo, $orgId, $lines);
            try {
                $pdo->beginTransaction();
                // Determine next sequential invoice number for this organisation
                $stmtNum = $pdo->prepare('SELECT COALESCE(MAX(number),0)+1 AS nextNum FROM invoice WHERE organization_id = :orgId');
                $stmtNum->execute(['orgId' => $orgId]);
                $nextNumber = (int)$stmtNum->fetchColumn();
                // Insert invoice header
                $stmt = $pdo->prepare(
                    'INSERT INTO invoice (guid, organization_id, currency, language, external_reference, description, comment, invoice_date, address, number, contact_name, show_lines_incl_vat, total_excl_vat, total_vatable_amount, total_incl_vat, total_non_vatable_amount, total_vat, invoice_template_id, status, contact_guid, payment_condition_number_of_days, payment_condition_type, reminder_fee, reminder_interest_rate, is_mobile_pay_invoice_enabled, is_penso_pay_enabled) VALUES (:guid,:orgId,:currency,:language,:external_reference,:description,:comment,:invoice_date,:address,:number,:contact_name,:show_lines_incl_vat,:total_excl_vat,:total_vatable_amount,:total_incl_vat,:total_non_vatable_amount,:total_vat,:invoice_template_id,:status,:contact_guid,:payment_condition_number_of_days,:payment_condition_type,:reminder_fee,:reminder_interest_rate,:is_mobile_pay_invoice_enabled,:is_penso_pay_enabled)'
                );
                $stmt->execute([
                    'guid' => $guid,
                    'orgId' => $orgId,
                    'currency' => $data['currency'] ?? 'DKK',
                    'language' => $data['language'] ?? 'da-DK',
                    'external_reference' => $data['externalReference'] ?? null,
                    'description' => $data['description'] ?? null,
                    'comment' => $data['comment'] ?? null,
                    'invoice_date' => $data['date'] ?? date('Y-m-d'),
                    'address' => $data['address'] ?? null,
                    'number' => $nextNumber,
                    'contact_name' => $data['contactName'] ?? null,
                    'show_lines_incl_vat' => !empty($data['showLinesInclVat']) ? 1 : 0,
                    'total_excl_vat' => $totals['totalExclVat'],
                    'total_vatable_amount' => $totals['totalVatableAmount'],
                    'total_incl_vat' => $totals['totalInclVat'],
                    'total_non_vatable_amount' => $totals['totalNonVatableAmount'],
                    'total_vat' => $totals['totalVat'],
                    'invoice_template_id' => $data['invoiceTemplateId'] ?? null,
                    'status' => 'Draft',
                    'contact_guid' => $data['contactGuid'] ?? null,
                    'payment_condition_number_of_days' => $data['paymentConditionNumberOfDays'] ?? 14,
                    'payment_condition_type' => $data['paymentConditionType'] ?? 'Netto',
                    'reminder_fee' => $data['reminderFee'] ?? 0,
                    'reminder_interest_rate' => $data['reminderInterestRate'] ?? 0,
                    'is_mobile_pay_invoice_enabled' => !empty($data['isMobilePayInvoiceEnabled']) ? 1 : 0,
                    'is_penso_pay_enabled' => !empty($data['isPensoPayEnabled']) ? 1 : 0
                ]);
                // Persist invoice lines
                persistInvoiceLines($pdo, $orgId, $guid, $lines);
                $pdo->commit();
            } catch (\Throwable $ex) {
                $pdo->rollBack();
                $response->getBody()->write(json_encode(['error' => 'Failed to create invoice','details' => $ex->getMessage()]));
                return $response->withStatus(500)->withHeader('Content-Type','application/json');
            }
            $response->getBody()->write(json_encode(['guid' => $guid, 'number' => $nextNumber, 'message' => 'Invoice created']));
            return $response->withStatus(201)->withHeader('Content-Type','application/json');
        });

        /**
         * Calculate invoice totals based on provided lines without persisting
         * the invoice.  Useful for previews.
         */
        $group->post('/fetch', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = $args['organizationId'];
            $data = json_decode($request->getBody()->getContents(), true);
            $lines = $data['productLines'] ?? $data['invoiceLines'] ?? null;
            if (!$lines || !is_array($lines) || count($lines) === 0) {
                $response->getBody()->write(json_encode(['error' => 'productLines/invoiceLines is required and must be a non-empty array']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            $totals = computeInvoiceTotals($pdo, $orgId, $lines);
            $response->getBody()->write(json_encode($totals));
            return $response->withHeader('Content-Type','application/json');
        });

        /**
         * Update an existing invoice.  All invoice lines are replaced by the
         * provided lines.  The invoice must still be in Draft state.
         */
        $group->put('/{guid}', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = $args['organizationId'];
            $guid  = $args['guid'];
            $data  = json_decode($request->getBody()->getContents(), true);
            // Check invoice exists and is not booked
            $stmtChk = $pdo->prepare('SELECT status FROM invoice WHERE organization_id = :orgId AND guid = :guid');
            $stmtChk->execute(['orgId' => $orgId, 'guid' => $guid]);
            $statusRow = $stmtChk->fetch(PDO::FETCH_ASSOC);
            if (!$statusRow) {
                $response->getBody()->write(json_encode(['error' => 'Invoice not found']));
                return $response->withStatus(404)->withHeader('Content-Type','application/json');
            }
            if ($statusRow['status'] !== 'Draft') {
                $response->getBody()->write(json_encode(['error' => 'Only draft invoices can be updated']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            $lines = $data['productLines'] ?? $data['invoiceLines'] ?? null;
            if ($lines && (!is_array($lines) || count($lines) === 0)) {
                $response->getBody()->write(json_encode(['error' => 'invoiceLines must be a non-empty array']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            try {
                $pdo->beginTransaction();
                // Update header fields
                $updateFields = ['currency','language','externalReference','description','comment','date','address','contactName','contactGuid','showLinesInclVat','paymentConditionNumberOfDays','paymentConditionType','reminderFee','reminderInterestRate','isMobilePayInvoiceEnabled','isPensoPayEnabled'];
                $setParts = [];
                $params = ['orgId' => $orgId, 'guid' => $guid];
                foreach ($updateFields as $field) {
                    $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $field));
                    $value = $data[$field] ?? $data[$snake] ?? null;
                    if ($value !== null) {
                        if ($field === 'date') {
                            $col = 'invoice_date';
                        } elseif ($field === 'externalReference') {
                            $col = 'external_reference';
                        } elseif ($field === 'contactName') {
                            $col = 'contact_name';
                        } elseif ($field === 'contactGuid') {
                            $col = 'contact_guid';
                        } elseif ($field === 'showLinesInclVat') {
                            $col = 'show_lines_incl_vat';
                            $value = $value ? 1 : 0;
                        } elseif ($field === 'paymentConditionNumberOfDays') {
                            $col = 'payment_condition_number_of_days';
                        } elseif ($field === 'paymentConditionType') {
                            $col = 'payment_condition_type';
                        } elseif ($field === 'reminderFee') {
                            $col = 'reminder_fee';
                        } elseif ($field === 'reminderInterestRate') {
                            $col = 'reminder_interest_rate';
                        } elseif ($field === 'isMobilePayInvoiceEnabled') {
                            $col = 'is_mobile_pay_invoice_enabled';
                            $value = $value ? 1 : 0;
                        } elseif ($field === 'isPensoPayEnabled') {
                            $col = 'is_penso_pay_enabled';
                            $value = $value ? 1 : 0;
                        } else {
                            $col = $snake;
                        }
                        $setParts[] = "$col = :$col";
                        $params[$col] = $value;
                    }
                }
                if (!empty($setParts)) {
                    $sqlUpd = 'UPDATE invoice SET ' . implode(', ', $setParts) . ', updated_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND guid = :guid';
                    $stmtUpd = $pdo->prepare($sqlUpd);
                    $stmtUpd->execute($params);
                }
                if ($lines) {
                    // Delete existing lines
                    $pdo->prepare('DELETE FROM invoice_lines WHERE invoice_guid = :guid')->execute(['guid'=>$guid]);
                    // Compute new totals and persist lines
                    $totals = computeInvoiceTotals($pdo, $orgId, $lines);
                    persistInvoiceLines($pdo, $orgId, $guid, $lines);
                    $stmtTot = $pdo->prepare('UPDATE invoice SET total_excl_vat = :total_excl, total_vatable_amount = :total_vatable, total_incl_vat = :total_incl, total_non_vatable_amount = :total_non_vatable, total_vat = :total_vat WHERE organization_id = :orgId AND guid = :guid');
                    $stmtTot->execute([
                        'total_excl' => $totals['totalExclVat'],
                        'total_vatable' => $totals['totalVatableAmount'],
                        'total_incl' => $totals['totalInclVat'],
                        'total_non_vatable' => $totals['totalNonVatableAmount'],
                        'total_vat' => $totals['totalVat'],
                        'orgId' => $orgId,
                        'guid' => $guid
                    ]);
                }
                $pdo->commit();
            } catch (\Throwable $ex) {
                $pdo->rollBack();
                $response->getBody()->write(json_encode(['error'=>'Failed to update invoice','details'=>$ex->getMessage()]));
                return $response->withStatus(500)->withHeader('Content-Type','application/json');
            }
            $response->getBody()->write(json_encode(['message'=>'Invoice updated','guid'=>$guid]));
            return $response->withHeader('Content-Type','application/json');
        });

        /**
         * Book an invoice.  Builds journal entries based on the invoice lines
         * and posts them to the ledger.  The accounts receivable and VAT
         * accounts are resolved via helper functions rather than hard coded
         * values.
         */
        $group->post('/{guid}/book', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = $args['organizationId'];
            $guid  = $args['guid'];
            // Load invoice and ensure it exists and is draft
            $stmtInv = $pdo->prepare('SELECT * FROM invoice WHERE organization_id = :orgId AND guid = :guid');
            $stmtInv->execute(['orgId' => $orgId, 'guid' => $guid]);
            $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                $response->getBody()->write(json_encode(['error' => 'Invoice not found']));
                return $response->withStatus(404)->withHeader('Content-Type','application/json');
            }
            if ($invoice['status'] !== 'Draft') {
                $response->getBody()->write(json_encode(['error' => 'Invoice must be in Draft status to be booked']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            // Retrieve invoice lines
            $stmtLines = $pdo->prepare('SELECT * FROM invoice_lines WHERE invoice_guid = :guid');
            $stmtLines->execute(['guid' => $guid]);
            $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
            if (!$lines) {
                $response->getBody()->write(json_encode(['error' => 'Invoice has no lines to book']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            $entries = [];
            // Build journal entries: debit accounts receivable, credit revenue accounts and VAT per line
            foreach ($lines as $line) {
                $totalIncl = (float)$line['total_amount_incl_vat'];
                $totalExcl = (float)$line['total_amount'];
                $vatAmount = $totalIncl - $totalExcl;
                // Debit accounts receivable
                $receivableAccount = getReceivableAccount($pdo, $orgId);
                $entries[] = [
                    'account_number' => $receivableAccount,
                    'description' => 'Invoice ' . $invoice['number'] . ' ' . ($line['description'] ?? ''),
                    'amount' => $totalIncl
                ];
                // Credit revenue account
                $entries[] = [
                    'account_number' => (int)$line['account_number'],
                    'description' => 'Invoice ' . $invoice['number'] . ' ' . ($line['description'] ?? ''),
                    'amount' => -$totalExcl
                ];
                // Credit VAT account if applicable
                if (abs($vatAmount) > 0.001) {
                    $vatCode = $line['vat_code'] ?? $line['vatCode'] ?? null;
                    $vatAccount = getVatAccount($pdo, $orgId, $vatCode);
                    $entries[] = [
                        'account_number' => $vatAccount,
                        'description' => 'VAT for invoice ' . $invoice['number'],
                        'amount' => -$vatAmount
                    ];
                }
            }
            // Validate entries balance
            $sum = array_sum(array_column($entries, 'amount'));
            if (abs($sum) > 0.001) {
                $response->getBody()->write(json_encode(['error' => 'Invoice entries are not balanced','sum' => $sum]));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            // Persist entries and update invoice status inside a transaction
            $pdo->beginTransaction();
            try {
                $stmtUpd = $pdo->prepare('UPDATE invoice SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND guid = :guid');
                $stmtUpd->execute(['status' => 'Booked', 'orgId' => $orgId, 'guid' => $guid]);
                foreach ($entries as $entry) {
                    $entryGuid = uniqid('entry_', true);
                    $stmtEntry = $pdo->prepare('INSERT INTO entries (organization_id, account_number, account_name, entry_date, voucher_number, voucher_type, description, vat_type, vat_code, amount, entry_guid, contact_guid, entry_type) VALUES (:orgId,:account_number,NULL,:entry_date,:voucher_number,:voucher_type,:description,NULL,:vat_code,:amount,:entry_guid,:contact_guid,:entry_type)');
                    $stmtEntry->execute([
                        'orgId' => $orgId,
                        'account_number' => $entry['account_number'],
                        'entry_date' => $invoice['invoice_date'],
                        'voucher_number' => $invoice['number'],
                        'voucher_type' => 'Invoice',
                        'description' => $entry['description'],
                        'vat_code' => $line['vat_code'] ?? $line['vatCode'] ?? null,
                        'amount' => $entry['amount'],
                        'entry_guid' => $entryGuid,
                        'contact_guid' => $invoice['contact_guid'],
                        'entry_type' => 'Normal'
                    ]);
                }
                $pdo->commit();
            } catch (\Throwable $ex) {
                $pdo->rollBack();
                $response->getBody()->write(json_encode(['error' => 'Booking invoice failed','details' => $ex->getMessage()]));
                return $response->withStatus(500)->withHeader('Content-Type','application/json');
            }
            $response->getBody()->write(json_encode(['guid' => $guid, 'message' => 'Invoice booked', 'voucherNumber' => $invoice['number']]));
            return $response->withHeader('Content-Type','application/json');
        });

        /**
         * Record a payment against a booked invoice.  Updates the invoice’s
         * payment status, overall status and calculates reminder fees and
         * interest when overdue.  When the outstanding balance reaches zero
         * the invoice is marked Paid.
         */
        $group->post('/{guid}/payment', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = $args['organizationId'];
            $guid  = $args['guid'];
            $data  = json_decode($request->getBody()->getContents(), true);
            $amount = (float)($data['amount'] ?? 0);
            if ($amount == 0) {
                $response->getBody()->write(json_encode(['error' => 'Payment amount must be non-zero']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            // Ensure invoice exists
            $stmtInv = $pdo->prepare('SELECT guid, total_incl_vat, payment_status, status, payment_condition_number_of_days, invoice_date, reminder_fee, reminder_interest_rate FROM invoice WHERE organization_id = :orgId AND guid = :guid');
            $stmtInv->execute(['orgId' => $orgId, 'guid' => $guid]);
            $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                $response->getBody()->write(json_encode(['error' => 'Invoice not found']));
                return $response->withStatus(404)->withHeader('Content-Type','application/json');
            }
            // Insert payment record
            $stmt = $pdo->prepare('INSERT INTO payments (invoice_guid, payment_date, payment_amount, payment_method, comments) VALUES (:invoice_guid,:payment_date,:payment_amount,:payment_method,:comments)');
            $stmt->execute([
                'invoice_guid' => $guid,
                'payment_date' => $data['paymentDate'] ?? date('Y-m-d'),
                'payment_amount' => $amount,
                'payment_method' => $data['paymentMethod'] ?? 'unknown',
                'comments' => $data['description'] ?? null
            ]);
            // Compute total paid so far
            $stmtSum = $pdo->prepare('SELECT SUM(payment_amount) FROM payments WHERE invoice_guid = :guid');
            $stmtSum->execute(['guid' => $guid]);
            $paid = (float)$stmtSum->fetchColumn();
            $remaining = (float)$invoice['total_incl_vat'] - $paid;
            $status = 'Booked';
            // Determine due date
            $dueDate = null;
            if (!empty($invoice['payment_condition_number_of_days']) && !empty($invoice['invoice_date'])) {
                $dueDate = (new \DateTime($invoice['invoice_date']))->modify('+' . $invoice['payment_condition_number_of_days'] . ' days')->format('Y-m-d');
            }
            $today = (new \DateTime())->format('Y-m-d');
            // Apply reminder fee and interest if overdue
            $additional = 0.0;
            if ($dueDate && $today > $dueDate && $remaining > 0) {
                $daysOverdue = (new \DateTime($dueDate))->diff(new \DateTime($today))->days;
                $interestRate = (float)$invoice['reminder_interest_rate'];
                $fee = (float)$invoice['reminder_fee'];
                $interestAmount = $remaining * ($interestRate / 100.0) * ($daysOverdue / 365.0);
                $additional = $fee + $interestAmount;
                $remaining += $additional;
                $status = 'Overdue';
            }
            if (abs($remaining) < 0.01) {
                $status = 'Paid';
                $remaining = 0.0;
            } elseif ($remaining < 0) {
                $status = 'OverPaid';
            } elseif ($status !== 'Overdue') {
                $status = 'Partial';
            }
            // Update invoice payment status and general status
            $stmtUpd = $pdo->prepare('UPDATE invoice SET payment_status = :payment_status, status = :status, payment_date = CASE WHEN :payment_status = "Paid" THEN :payment_date ELSE payment_date END WHERE organization_id = :orgId AND guid = :guid');
            $stmtUpd->execute([
                'payment_status' => $status,
                'status' => $status,
                'payment_date' => date('Y-m-d'),
                'orgId' => $orgId,
                'guid' => $guid
            ]);
            $response->getBody()->write(json_encode([
                'guid' => $guid,
                'paymentStatus' => $status,
                'remainingAmount' => round($remaining, 2),
                'additionalCharges' => round($additional, 2)
            ]));
            return $response->withHeader('Content-Type','application/json');
        });

        /**
         * Create a credit note from an existing invoice.  Copies each invoice
         * line with negative amounts and assigns a sequential credit note
         * number.  The new credit note is tied to the same organisation.
         */
        $group->post('/{guid}/creditnote', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = $args['organizationId'];
            $invoiceGuid = $args['guid'];
            // Load invoice
            $stmtInv = $pdo->prepare('SELECT * FROM invoice WHERE organization_id = :orgId AND guid = :guid');
            $stmtInv->execute(['orgId' => $orgId, 'guid' => $invoiceGuid]);
            $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                $response->getBody()->write(json_encode(['error' => 'Invoice not found']));
                return $response->withStatus(404)->withHeader('Content-Type','application/json');
            }
            // Determine next credit note number
            $stmtNum = $pdo->prepare('SELECT COALESCE(MAX(number),0)+1 FROM credit_note WHERE organization_id = :orgId');
            $stmtNum->execute(['orgId' => $orgId]);
            $nextNumber = (int)$stmtNum->fetchColumn();
            $creditGuid = Uuid::uuid4()->toString();
            try {
                $pdo->beginTransaction();
                // Insert credit note header with negative totals
                $stmt = $pdo->prepare('INSERT INTO credit_note (guid, organization_id, currency, language, external_reference, description, comment, credit_note_date, address, number, contact_name, contact_guid, show_lines_incl_vat, total_excl_vat, total_vatable_amount, total_incl_vat, total_non_vatable_amount, total_vat, invoice_template_id, status, payment_condition_number_of_days, payment_condition_type, fik_code, deposit_account_number, mail_out_status, latest_mail_out_type, is_sent_to_debt_collection, is_mobile_pay_invoice_enabled, is_penso_pay_enabled, credit_note_for) VALUES (:guid,:orgId,:currency,:language,:external_reference,:description,:comment,:credit_date,:address,:number,:contact_name,:contact_guid,:show_lines_incl_vat,:total_excl_vat,:total_vatable_amount,:total_incl_vat,:total_non_vatable_amount,:total_vat,:invoice_template_id,:status,:payment_condition_number_of_days,:payment_condition_type,:fik_code,:deposit_account_number,:mail_out_status,:latest_mail_out_type,:is_sent_to_debt_collection,:is_mobile_pay_invoice_enabled,:is_penso_pay_enabled,:credit_note_for)');
                $stmt->execute([
                    'guid' => $creditGuid,
                    'orgId' => $orgId,
                    'currency' => $invoice['currency'],
                    'language' => $invoice['language'],
                    'external_reference' => $invoice['external_reference'],
                    'description' => $invoice['description'],
                    'comment' => $invoice['comment'],
                    'credit_date' => date('Y-m-d'),
                    'address' => $invoice['address'],
                    'number' => $nextNumber,
                    'contact_name' => $invoice['contact_name'],
                    'contact_guid' => $invoice['contact_guid'],
                    'show_lines_incl_vat' => $invoice['show_lines_incl_vat'],
                    'total_excl_vat' => -$invoice['total_excl_vat'],
                    'total_vatable_amount' => -$invoice['total_vatable_amount'],
                    'total_incl_vat' => -$invoice['total_incl_vat'],
                    'total_non_vatable_amount' => -$invoice['total_non_vatable_amount'],
                    'total_vat' => -$invoice['total_vat'],
                    'invoice_template_id' => $invoice['invoice_template_id'],
                    'status' => 'Draft',
                    'payment_condition_number_of_days' => $invoice['payment_condition_number_of_days'],
                    'payment_condition_type' => $invoice['payment_condition_type'],
                    'fik_code' => $invoice['fik_code'],
                    'deposit_account_number' => $invoice['deposit_account_number'],
                    'mail_out_status' => $invoice['mail_out_status'],
                    'latest_mail_out_type' => $invoice['latest_mail_out_type'],
                    'is_sent_to_debt_collection' => $invoice['is_sent_to_debt_collection'] ?? 0,
                    'is_mobile_pay_invoice_enabled' => $invoice['is_mobile_pay_invoice_enabled'] ?? 0,
                    'is_penso_pay_enabled' => $invoice['is_penso_pay_enabled'] ?? 0,
                    'credit_note_for' => $invoiceGuid
                ]);
                // Copy invoice lines as negative into credit_note_lines
                $stmtLines = $pdo->prepare('SELECT * FROM invoice_lines WHERE invoice_guid = :guid');
                $stmtLines->execute(['guid' => $invoiceGuid]);
                $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
                foreach ($lines as $line) {
                    $stmtCN = $pdo->prepare('INSERT INTO credit_note_lines (credit_note_guid, product_guid, description, comments, quantity, account_number, unit, discount, line_type, account_name, base_amount_value, base_amount_value_incl_vat, total_amount, total_amount_incl_vat) VALUES (:creditGuid,:product_guid,:description,:comments,:quantity,:account_number,:unit,:discount,:line_type,:account_name,:base_amount_value,:base_amount_value_incl_vat,:total_amount,:total_amount_incl_vat)');
                    $stmtCN->execute([
                        'creditGuid' => $creditGuid,
                        'product_guid' => $line['product_guid'],
                        'description' => $line['description'],
                        'comments' => $line['comments'],
                        'quantity' => -1 * $line['quantity'],
                        'account_number' => $line['account_number'],
                        'unit' => $line['unit'],
                        'discount' => $line['discount'],
                        'line_type' => $line['line_type'],
                        'account_name' => $line['account_name'],
                        'base_amount_value' => -1 * $line['base_amount_value'],
                        'base_amount_value_incl_vat' => -1 * $line['base_amount_value_incl_vat'],
                        'total_amount' => -1 * $line['total_amount'],
                        'total_amount_incl_vat' => -1 * $line['total_amount_incl_vat']
                    ]);
                }
                $pdo->commit();
            } catch (\Throwable $ex) {
                $pdo->rollBack();
                $response->getBody()->write(json_encode(['error' => 'Failed to create credit note','details'=>$ex->getMessage()]));
                return $response->withStatus(500)->withHeader('Content-Type','application/json');
            }
            $response->getBody()->write(json_encode(['guid' => $creditGuid, 'number' => $nextNumber, 'message' => 'Credit note created']));
            return $response->withStatus(201)->withHeader('Content-Type','application/json');
        });

        /**
         * Soft delete or hard delete an invoice.  Draft invoices are
         * hard-deleted, booked invoices are soft-deleted.
         */
        $group->delete('/{guid}', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = $args['organizationId'];
            $guid  = $args['guid'];
            $stmt = $pdo->prepare('SELECT status FROM invoice WHERE organization_id = :orgId AND guid = :guid');
            $stmt->execute(['orgId' => $orgId, 'guid' => $guid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $response->getBody()->write(json_encode(['error' => 'Invoice not found']));
                return $response->withStatus(404)->withHeader('Content-Type','application/json');
            }
            if ($row['status'] === 'Draft') {
                // Hard delete
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM invoice_lines WHERE invoice_guid = :guid')->execute(['guid'=>$guid]);
                $pdo->prepare('DELETE FROM invoice WHERE organization_id = :orgId AND guid = :guid')->execute(['orgId'=>$orgId,'guid'=>$guid]);
                $pdo->commit();
            } else {
                // Soft delete: set deleted_at
                $pdo->prepare('UPDATE invoice SET deleted_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND guid = :guid')->execute(['orgId'=>$orgId,'guid'=>$guid]);
            }
            $response->getBody()->write(json_encode(['guid'=>$guid,'message'=>'Invoice deleted']));
            return $response->withHeader('Content-Type','application/json');
        });

        /**
         * Send an invoice by email.  Validates the request body and marks
         * the invoice as emailed.  A real implementation should integrate
         * with a mailer library (e.g. PHPMailer or Symfony Mailer).
         */
        $group->post('/{guid}/email', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = $args['organizationId'];
            $guid  = $args['guid'];
            $data  = json_decode($request->getBody()->getContents(), true);
            foreach (['receiver','subject','message'] as $field) {
                if (empty($data[$field])) {
                    $response->getBody()->write(json_encode(['error'=>"$field is required"]));
                    return $response->withStatus(400)->withHeader('Content-Type','application/json');
                }
            }
            // Simulate email sending
            $emailSent = true;
            if ($emailSent) {
                // Update mail_out_status and latest_mail_out_type
                $stmt = $pdo->prepare('UPDATE invoice SET mail_out_status = :status, latest_mail_out_type = :type WHERE organization_id = :orgId AND guid = :guid');
                $stmt->execute([
                    'status' => 'Sent',
                    'type' => 'Email',
                    'orgId' => $orgId,
                    'guid' => $guid
                ]);
                $response->getBody()->write(json_encode(['guid'=>$guid,'message'=>'Email sent successfully']));
                return $response->withHeader('Content-Type','application/json');
            }
            $response->getBody()->write(json_encode(['error'=>'Failed to send email']));
            return $response->withStatus(500)->withHeader('Content-Type','application/json');
        });
    });
};

/**
 * Compute invoice totals from a set of invoice lines.
 *
 * Each line may specify a `vatRate` directly or reference a `vat_code`.
 * If `vat_code`/`vatCode` is provided the rate is looked up in the
 * `vat_type` table.  Returns an array with the keys:
 *   totalExclVat, totalInclVat, totalVat, totalVatableAmount,
 *   totalNonVatableAmount.
 *
 * Missing quantities default to 1 and missing unit prices default to 0.
 */
function computeInvoiceTotals(PDO $pdo, $orgId, array $lines): array
{
    $totalExcl = 0.0;
    $totalVat = 0.0;
    $totalIncl = 0.0;
    $vatable = 0.0;
    $nonVatable = 0.0;
    foreach ($lines as $line) {
        $qty = isset($line['quantity']) ? (float)$line['quantity'] : 1.0;
        $unitPrice = null;
        if (isset($line['unitPrice'])) {
            $unitPrice = (float)$line['unitPrice'];
        } elseif (isset($line['base_amount_value'])) {
            $unitPrice = (float)$line['base_amount_value'];
        } else {
            $unitPrice = 0.0;
        }
        $discount = isset($line['discount']) ? (float)$line['discount'] : 0.0;
        $discountFactor = (100.0 - $discount) / 100.0;
        $vatRate = 0.0;
        if (!empty($line['vatRate'])) {
            $vatRate = ((float)$line['vatRate']) / 100.0;
        } elseif (!empty($line['vat_code']) || !empty($line['vatCode'])) {
            $code = $line['vat_code'] ?? $line['vatCode'];
            // look up vat_rate in vat_type
            $stmt = $pdo->prepare('SELECT vat_rate FROM vat_type WHERE vat_code = :code');
            $stmt->execute(['code' => $code]);
            $res = $stmt->fetchColumn();
            if ($res !== false) {
                $vatRate = ((float)$res) / 100.0;
            }
        }
        $base = $unitPrice * $discountFactor;
        $baseIncl = $base * (1 + $vatRate);
        $lineBaseTotal = $base * $qty;
        $lineInclTotal = $baseIncl * $qty;
        $lineVat = $lineInclTotal - $lineBaseTotal;
        $totalExcl += $lineBaseTotal;
        $totalVat += $lineVat;
        $totalIncl += $lineInclTotal;
        if ($vatRate > 0) {
            $vatable += $lineBaseTotal;
        } else {
            $nonVatable += $lineBaseTotal;
        }
    }
    return [
        'totalExclVat' => round($totalExcl, 2),
        'totalInclVat' => round($totalIncl, 2),
        'totalVat' => round($totalVat, 2),
        'totalVatableAmount' => round($vatable, 2),
        'totalNonVatableAmount' => round($nonVatable, 2)
    ];
}

/**
 * Persist invoice lines to the database for a specific invoice.
 *
 * Inserts each line into the `invoice_lines` table after computing derived
 * fields such as base amount, base amount including VAT, total amounts and
 * VAT rate.  If an account number is provided the corresponding account
 * name is looked up in the `account` table for the given organisation.
 */
function persistInvoiceLines(PDO $pdo, $orgId, string $invoiceGuid, array $lines): void
{
    foreach ($lines as $line) {
        $qty = isset($line['quantity']) ? (float)$line['quantity'] : 1.0;
        $unitPrice = null;
        if (isset($line['unitPrice'])) {
            $unitPrice = (float)$line['unitPrice'];
        } elseif (isset($line['base_amount_value'])) {
            $unitPrice = (float)$line['base_amount_value'];
        } else {
            $unitPrice = 0.0;
        }
        $discount = isset($line['discount']) ? (float)$line['discount'] : 0.0;
        $discountFactor = (100.0 - $discount) / 100.0;
        $vatRate = 0.0;
        $vatCode = $line['vat_code'] ?? $line['vatCode'] ?? null;
        if (isset($line['vatRate'])) {
            $vatRate = ((float)$line['vatRate']) / 100.0;
        } elseif ($vatCode) {
            $stmt = $pdo->prepare('SELECT vat_rate FROM vat_type WHERE vat_code = :code');
            $stmt->execute(['code' => $vatCode]);
            $res = $stmt->fetchColumn();
            if ($res !== false) {
                $vatRate = ((float)$res) / 100.0;
            }
        }
        $base = $unitPrice * $discountFactor;
        $baseIncl = $base * (1 + $vatRate);
        $lineBaseTotal = $base * $qty;
        $lineInclTotal = $baseIncl * $qty;
        $accountNumber = (int)($line['account_number'] ?? $line['accountNumber'] ?? 0);
        // Resolve account name
        $accountName = null;
        if ($accountNumber) {
            $stmt = $pdo->prepare('SELECT name FROM account WHERE organization_id = :orgId AND accountNumber = :accNum');
            $stmt->execute(['orgId' => $orgId, 'accNum' => $accountNumber]);
            $accountName = $stmt->fetchColumn();
        }
        $stmtIns = $pdo->prepare('INSERT INTO invoice_lines (invoice_guid, product_guid, description, comments, quantity, account_number, account_name, unit, discount, line_type, vat_code, vat_rate, base_amount_value, base_amount_value_incl_vat, total_amount, total_amount_incl_vat) VALUES (:invoice_guid,:product_guid,:description,:comments,:quantity,:account_number,:account_name,:unit,:discount,:line_type,:vat_code,:vat_rate,:base,:base_incl,:total,:total_incl)');
        $stmtIns->execute([
            'invoice_guid' => $invoiceGuid,
            'product_guid' => $line['productGuid'] ?? $line['product_guid'] ?? null,
            'description' => $line['description'] ?? '',
            'comments' => $line['comments'] ?? null,
            'quantity' => $qty,
            'account_number' => $accountNumber,
            'account_name' => $accountName,
            'unit' => $line['unit'] ?? 'pcs',
            'discount' => $discount,
            'line_type' => $line['lineType'] ?? $line['line_type'] ?? 'Product',
            'vat_code' => $vatCode,
            'vat_rate' => $vatRate,
            'base' => $base,
            'base_incl' => $baseIncl,
            'total' => $lineBaseTotal,
            'total_incl' => $lineInclTotal
        ]);
    }
}

/**
 * Resolve the accounts receivable account number for an organisation.
 *
 * Looks up a default accounts receivable account for the organisation.  If
 * none is configured a fallback of 1100 is returned.  Organisations may
 * store their receivables account in a settings table or via the chart of
 * accounts.
 */
function getReceivableAccount(PDO $pdo, $orgId): int
{
    // Try to find a configured receivables account on organisation settings
    $stmt = $pdo->prepare('SELECT default_receivable_account FROM organizations WHERE id = :id');
    $stmt->execute(['id' => $orgId]);
    $acc = $stmt->fetchColumn();
    if ($acc) {
        return (int)$acc;
    }
    return 1100;
}

/**
 * Resolve the VAT account number based on a VAT code for an organisation.
 *
 * Attempts to look up the VAT account associated with the provided VAT code
 * in the organisation’s chart of accounts.  If no specific account is
 * configured for the VAT code a fallback of 2610 is returned.
 */
function getVatAccount(PDO $pdo, $orgId, ?string $vatCode): int
{
    if ($vatCode) {
        // Join account and vat_type by vatCode if such relation exists
        // Attempt 1: account table may have a vatCode column
        $stmt = $pdo->prepare('SELECT accountNumber FROM account WHERE organization_id = :orgId AND vatCode = :code');
        $stmt->execute(['orgId' => $orgId, 'code' => $vatCode]);
        $acc = $stmt->fetchColumn();
        if ($acc) {
            return (int)$acc;
        }
        // Attempt 2: there may be a mapping table
        $stmt2 = $pdo->prepare('SELECT vat_account_number FROM vat_type WHERE vat_code = :code');
        $stmt2->execute(['code' => $vatCode]);
        $acc2 = $stmt2->fetchColumn();
        if ($acc2) {
            return (int)$acc2;
        }
    }
    return 2610;
}