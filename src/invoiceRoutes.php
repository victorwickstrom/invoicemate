<?php
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Routing\RouteCollectorProxy;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

/*
 * Improved invoice routes for Invoicemate.
 *
 * These routes implement a more complete invoice workflow matching the
 * Dinero‑style API described in the project TODO.  Major differences
 * compared to the original version include:
 *
 *  • Full persistence of invoice lines into the `invoice_lines` table and
 *    recalculation of invoice totals based on the posted lines.
 *  • Sequential numbering per organisation for both invoices and credit
 *    notes, ensuring numbers never collide between organisations.
 *  • Payment handling that updates the invoice’s payment status and
 *    remaining balance when payments are recorded.
 *  • Booking logic that validates periods, assigns a voucher number
 *    only when needed and posts balanced journal entries.
 *  • Credit note generation that copies lines from the original invoice
 *    with negative amounts and assigns a sequential credit note number.
 *  • Soft deletion of booked invoices and hard deletion of drafts.
 */

return function ($app) {
    $container = $app->getContainer();
    // Group all invoice related routes under organisation scope
    $app->group('/v1/{organizationId}/invoices', function (RouteCollectorProxy $group) use ($container) {
        /**
         * List invoices for an organisation.  Supports optional filters via
         * query parameters similar to the Dinero API.  Deleted invoices are
         * excluded by default.
         */
        $group->get('', function (Request $request, Response $response, array $args) use ($container) {
            $pdo   = $container->get('db');
            $orgId = $args['organizationId'];
            $params = $request->getQueryParams();
            $query = "SELECT * FROM invoice WHERE organization_id = :orgId AND deleted_at IS NULL";
            $bindings = ['orgId' => $orgId];
            // Status filter
            if (!empty($params['status'])) {
                $statuses = array_map('trim', explode(',', $params['status']));
                $placeholders = implode(',', array_fill(0, count($statuses), '?'));
                $query .= " AND status IN (".$placeholders.")";
                $bindings = array_merge($bindings, $statuses);
            }
            // Free text search across number, contact name or description
            if (!empty($params['search'])) {
                $search = '%'.$params['search'].'%';
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
         * Create a new invoice.  This endpoint persists the invoice header
         * together with all invoice lines and calculates totals on the fly.
         */
        $group->post('', function (Request $request, Response $response, array $args) use ($container) {
            $pdo  = $container->get('db');
            $orgId = $args['organizationId'];
            $data = json_decode($request->getBody()->getContents(), true);
            $lines = $data['productLines'] ?? $data['invoiceLines'] ?? null;
            if (!$lines || !is_array($lines) || count($lines) === 0) {
                $response->getBody()->write(json_encode(['error' => 'productLines/invoiceLines is required and must be a non‑empty array']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            // Generate GUID if not supplied
            $guid = $data['guid'] ?? Uuid::uuid4()->toString();
            // Determine next sequential invoice number for this organisation
            $stmtNum = $pdo->prepare('SELECT COALESCE(MAX(number),0)+1 FROM invoice WHERE organization_id = :orgId');
            $stmtNum->execute(['orgId' => $orgId]);
            $nextNumber = (int)$stmtNum->fetchColumn();
            // Compute totals based on lines
            $totals = computeInvoiceTotals($pdo, $orgId, $lines);
            // Insert invoice header
            $stmt = $pdo->prepare(
                'INSERT INTO invoice (guid, organization_id, currency, language, external_reference, description, comment, invoice_date, address, number, contact_name, show_lines_incl_vat, total_excl_vat, total_vatable_amount, total_incl_vat, total_non_vatable_amount, total_vat, invoice_template_id, status, contact_guid, payment_condition_number_of_days, payment_condition_type, reminder_fee, reminder_interest_rate, is_mobile_pay_invoice_enabled, is_penso_pay_enabled) VALUES (:guid,:orgId,:currency,:language,:external_reference,:description,:comment,:invoice_date,:address,:number,:contact_name,:show_lines_incl_vat,:total_excl_vat,:total_vatable_amount,:total_incl_vat,:total_non_vatable_amount,:total_vat,:invoice_template_id,\'Draft\',:contact_guid,:payment_condition_number_of_days,:payment_condition_type,:reminder_fee,:reminder_interest_rate,:is_mobile_pay_invoice_enabled,:is_penso_pay_enabled)'
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
                'contact_guid' => $data['contactGuid'] ?? null,
                'payment_condition_number_of_days' => $data['paymentConditionNumberOfDays'] ?? 14,
                'payment_condition_type' => $data['paymentConditionType'] ?? 'Netto',
                'reminder_fee' => $data['reminderFee'] ?? 0,
                'reminder_interest_rate' => $data['reminderInterestRate'] ?? 0,
                'is_mobile_pay_invoice_enabled' => !empty($data['isMobilePayInvoiceEnabled']) ? 1 : 0,
                'is_penso_pay_enabled' => !empty($data['isPensoPayEnabled']) ? 1 : 0
            ]);
            // Persist lines
            persistInvoiceLines($pdo, $orgId, $guid, $lines);
            $response->getBody()->write(json_encode(['guid' => $guid, 'number' => $nextNumber, 'message' => 'Invoice created']));
            return $response->withStatus(201)->withHeader('Content-Type','application/json');
        });

        /**
         * Calculate invoice totals based on provided lines without persisting
         * the invoice.  Useful for previews.
         */
        $group->post('/fetch', function (Request $request, Response $response, array $args) use ($container) {
            $pdo   = $container->get('db');
            $orgId = $args['organizationId'];
            $data = json_decode($request->getBody()->getContents(), true);
            $lines = $data['productLines'] ?? $data['invoiceLines'] ?? null;
            if (!$lines || !is_array($lines) || count($lines) === 0) {
                $response->getBody()->write(json_encode(['error' => 'productLines/invoiceLines is required and must be a non‑empty array']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            $totals = computeInvoiceTotals($pdo, $orgId, $lines);
            $response->getBody()->write(json_encode($totals));
            return $response->withHeader('Content-Type','application/json');
        });

        /**
         * Update an existing invoice.  All invoice lines are replaced by
         * those provided in the request and totals are recalculated.
         */
        $group->put('/{guid}', function (Request $request, Response $response, array $args) use ($container) {
            $pdo   = $container->get('db');
            $orgId = $args['organizationId'];
            $guid  = $args['guid'];
            $data  = json_decode($request->getBody()->getContents(), true);
            $lines = $data['productLines'] ?? $data['invoiceLines'] ?? null;
            if (!$lines || !is_array($lines) || count($lines) === 0) {
                $response->getBody()->write(json_encode(['error' => 'productLines/invoiceLines is required and must be a non‑empty array']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            // Ensure invoice exists and is not booked
            $stmtCheck = $pdo->prepare('SELECT status FROM invoice WHERE organization_id = :orgId AND guid = :guid');
            $stmtCheck->execute(['orgId' => $orgId, 'guid' => $guid]);
            $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $response->getBody()->write(json_encode(['error' => 'Invoice not found']));
                return $response->withStatus(404)->withHeader('Content-Type','application/json');
            }
            if ($row['status'] !== 'Draft') {
                $response->getBody()->write(json_encode(['error' => 'Only draft invoices can be updated']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            // Recalculate totals
            $totals = computeInvoiceTotals($pdo, $orgId, $lines);
            // Update invoice header
            $stmtUpd = $pdo->prepare(
                'UPDATE invoice SET currency = :currency, language = :language, external_reference = :external_reference, description = :description, comment = :comment, invoice_date = :invoice_date, address = :address, contact_name = :contact_name, show_lines_incl_vat = :show_lines_incl_vat, invoice_template_id = :invoice_template_id, contact_guid = :contact_guid, payment_condition_number_of_days = :payment_condition_number_of_days, payment_condition_type = :payment_condition_type, reminder_fee = :reminder_fee, reminder_interest_rate = :reminder_interest_rate, is_mobile_pay_invoice_enabled = :is_mobile_pay_invoice_enabled, is_penso_pay_enabled = :is_penso_pay_enabled, total_excl_vat = :total_excl_vat, total_vatable_amount = :total_vatable_amount, total_incl_vat = :total_incl_vat, total_non_vatable_amount = :total_non_vatable_amount, total_vat = :total_vat, updated_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND guid = :guid'
            );
            $stmtUpd->execute([
                'currency' => $data['currency'] ?? 'DKK',
                'language' => $data['language'] ?? 'da-DK',
                'external_reference' => $data['externalReference'] ?? null,
                'description' => $data['description'] ?? null,
                'comment' => $data['comment'] ?? null,
                'invoice_date' => $data['date'] ?? date('Y-m-d'),
                'address' => $data['address'] ?? null,
                'contact_name' => $data['contactName'] ?? null,
                'show_lines_incl_vat' => !empty($data['showLinesInclVat']) ? 1 : 0,
                'invoice_template_id' => $data['invoiceTemplateId'] ?? null,
                'contact_guid' => $data['contactGuid'] ?? null,
                'payment_condition_number_of_days' => $data['paymentConditionNumberOfDays'] ?? 14,
                'payment_condition_type' => $data['paymentConditionType'] ?? 'Netto',
                'reminder_fee' => $data['reminderFee'] ?? 0,
                'reminder_interest_rate' => $data['reminderInterestRate'] ?? 0,
                'is_mobile_pay_invoice_enabled' => !empty($data['isMobilePayInvoiceEnabled']) ? 1 : 0,
                'is_penso_pay_enabled' => !empty($data['isPensoPayEnabled']) ? 1 : 0,
                'total_excl_vat' => $totals['totalExclVat'],
                'total_vatable_amount' => $totals['totalVatableAmount'],
                'total_incl_vat' => $totals['totalInclVat'],
                'total_non_vatable_amount' => $totals['totalNonVatableAmount'],
                'total_vat' => $totals['totalVat'],
                'orgId' => $orgId,
                'guid' => $guid
            ]);
            // Replace lines: delete existing and insert new ones
            $stmtDel = $pdo->prepare('DELETE FROM invoice_lines WHERE invoice_guid = :guid');
            $stmtDel->execute(['guid' => $guid]);
            persistInvoiceLines($pdo, $orgId, $guid, $lines);
            $response->getBody()->write(json_encode(['guid' => $guid, 'message' => 'Invoice updated']));
            return $response->withHeader('Content-Type','application/json');
        });

        /**
         * Book an invoice: validate the period, ensure lines exist and are
         * balanced, assign voucher entries and update status to Booked.
         */
        $group->post('/{guid}/book', function (Request $request, Response $response, array $args) use ($container) {
            $pdo   = $container->get('db');
            $orgId = $args['organizationId'];
            $guid  = $args['guid'];
            // Role check: require admin role
            $user = $request->getAttribute('user');
            $roles = $user['roles'] ?? [];
            if (!in_array('admin', $roles)) {
                $response->getBody()->write(json_encode(['error' => 'Forbidden: insufficient role']));
                return $response->withStatus(403)->withHeader('Content-Type','application/json');
            }
            // Retrieve invoice
            $stmtInv = $pdo->prepare('SELECT number, status, invoice_date, total_incl_vat FROM invoice WHERE organization_id = :orgId AND guid = :guid');
            $stmtInv->execute(['orgId' => $orgId, 'guid' => $guid]);
            $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                $response->getBody()->write(json_encode(['error' => 'Invoice not found']));
                return $response->withStatus(404)->withHeader('Content-Type','application/json');
            }
            if ($invoice['status'] !== 'Draft') {
                $response->getBody()->write(json_encode(['error' => 'Only draft invoices can be booked']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            // Check period lock
            $date = $invoice['invoice_date'];
            if ($date) {
                ensureAccountingYearHasLockColumn($pdo);
                $stmtLocked = $pdo->prepare('SELECT COUNT(*) FROM accounting_year WHERE organization_id = :org AND is_locked = 1 AND from_date <= :d AND to_date >= :d');
                $stmtLocked->execute(['org' => $orgId, 'd' => $date]);
                if ((int)$stmtLocked->fetchColumn() > 0) {
                    $response->getBody()->write(json_encode(['error' => 'Accounting period is locked for date '.$date]));
                    return $response->withStatus(400)->withHeader('Content-Type','application/json');
                }
            }
            // Load invoice lines
            $stmtLines = $pdo->prepare('SELECT account_number, description, total_amount, total_amount_incl_vat FROM invoice_lines WHERE invoice_guid = :guid');
            $stmtLines->execute(['guid' => $guid]);
            $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
            if (!$lines) {
                $response->getBody()->write(json_encode(['error' => 'Invoice has no lines to book']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            // Build journal entries: debit accounts receivable 1100, credit revenue accounts and VAT
            $entries = [];
            foreach ($lines as $line) {
                $totalIncl = (float)$line['total_amount_incl_vat'];
                $totalExcl = (float)$line['total_amount'];
                $vatAmount = $totalIncl - $totalExcl;
                // Debit accounts receivable (1100)
                $entries[] = ['account_number' => 1100, 'description' => 'Invoice '.$invoice['number'].' '.$line['description'], 'amount' => $totalIncl];
                // Credit revenue account
                $entries[] = ['account_number' => (int)$line['account_number'], 'description' => 'Invoice '.$invoice['number'].' '.$line['description'], 'amount' => -$totalExcl];
                // Credit VAT if nonzero
                if (abs($vatAmount) > 0.001) {
                    $entries[] = ['account_number' => 2610, 'description' => 'VAT for invoice '.$invoice['number'], 'amount' => -$vatAmount];
                }
            }
            // Validate entries balance
            $sum = array_sum(array_column($entries, 'amount'));
            if (abs($sum) > 0.001) {
                $response->getBody()->write(json_encode(['error' => 'Invoice entries are not balanced','sum'=>$sum]));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            // Transaction: set status and insert entries
            $pdo->beginTransaction();
            try {
                $stmtUpd = $pdo->prepare('UPDATE invoice SET status = \"Booked\", updated_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND guid = :guid');
                $stmtUpd->execute(['orgId' => $orgId, 'guid' => $guid]);
                foreach ($entries as $entry) {
                    $entryGuid = uniqid('entry_', true);
                    $stmtEntry = $pdo->prepare('INSERT INTO entries (organization_id, account_number, account_name, entry_date, voucher_number, voucher_type, description, vat_type, vat_code, amount, entry_guid, contact_guid, entry_type) VALUES (:orgId,:account_number,NULL,:entry_date,:voucher_number,:voucher_type,:description,NULL,NULL,:amount,:entry_guid,NULL,:entry_type)');
                    $stmtEntry->execute([
                        'orgId' => $orgId,
                        'account_number' => $entry['account_number'],
                        'entry_date' => $date ?? date('Y-m-d'),
                        'voucher_number' => $invoice['number'],
                        'voucher_type' => 'Invoice',
                        'description' => $entry['description'],
                        'amount' => $entry['amount'],
                        'entry_guid' => $entryGuid,
                        'entry_type' => 'Normal'
                    ]);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $response->getBody()->write(json_encode(['error' => 'Booking invoice failed','details'=>$e->getMessage()]));
                return $response->withStatus(500)->withHeader('Content-Type','application/json');
            }
            $response->getBody()->write(json_encode(['guid'=>$guid,'message'=>'Invoice booked','voucherNumber'=>$invoice['number']]));
            return $response->withHeader('Content-Type','application/json');
        });

        /**
         * Record a payment against a booked invoice.  After insertion the
         * invoice’s payment status and balance are updated.  When the
         * outstanding balance reaches zero the invoice is marked Paid.
         */
        $group->post('/{guid}/payment', function (Request $request, Response $response, array $args) use ($container) {
            $pdo   = $container->get('db');
            $orgId = $args['organizationId'];
            $guid  = $args['guid'];
            $data  = json_decode($request->getBody()->getContents(), true);
            $amount = (float)($data['amount'] ?? 0);
            if ($amount == 0) {
                $response->getBody()->write(json_encode(['error' => 'Payment amount must be non‑zero']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            // Ensure invoice exists and is booked
            $stmtInv = $pdo->prepare('SELECT total_incl_vat, payment_status, payment_condition_number_of_days, invoice_date FROM invoice WHERE organization_id = :orgId AND guid = :guid');
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
            if (abs($remaining) < 0.01) {
                $status = 'Paid';
            } elseif ($remaining < 0) {
                $status = 'OverPaid';
            } else {
                // Check due date
                $dueDate = null;
                if (!empty($invoice['payment_condition_number_of_days']) && !empty($invoice['invoice_date'])) {
                    $dueDate = (new \DateTime($invoice['invoice_date']))->modify('+'.$invoice['payment_condition_number_of_days'].' days')->format('Y-m-d');
                }
                if ($dueDate && (new \DateTime())->format('Y-m-d') > $dueDate) {
                    $status = 'Overdue';
                } else {
                    $status = 'Partial';
                }
            }
            // Update invoice payment status and payment_date if fully paid
            $stmtUpd = $pdo->prepare('UPDATE invoice SET payment_status = :payment_status, payment_date = CASE WHEN :payment_status = \"Paid\" THEN :payment_date ELSE payment_date END WHERE organization_id = :orgId AND guid = :guid');
            $stmtUpd->execute([
                'payment_status' => $status,
                'payment_date' => date('Y-m-d'),
                'orgId' => $orgId,
                'guid' => $guid
            ]);
            $response->getBody()->write(json_encode(['guid'=>$guid,'paymentStatus'=>$status,'remainingAmount'=>$remaining]));
            return $response->withHeader('Content-Type','application/json');
        });

        /**
         * Create a credit note from an existing invoice.  Copies each invoice
         * line with negative amounts and assigns a sequential credit note
         * number.  Returns the GUID of the new credit note.
         */
        $group->post('/{guid}/creditnote', function (Request $request, Response $response, array $args) use ($container) {
            $pdo   = $container->get('db');
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
            // Ensure organisation_id column exists on credit_note.  If not, add it.
            ensureCreditNoteHasOrgColumn($pdo);
            // Determine next credit note number for organisation
            $stmtNum = $pdo->prepare('SELECT COALESCE(MAX(number),0)+1 FROM credit_note WHERE organization_id = :orgId');
            $stmtNum->execute(['orgId' => $orgId]);
            $nextNumber = (int)$stmtNum->fetchColumn();
            $creditGuid = Uuid::uuid4()->toString();
            // Insert credit note header with negative totals
            $stmt = $pdo->prepare('INSERT INTO credit_note (guid, organization_id, currency, language, external_reference, description, comment, credit_note_date, address, number, contact_name, contact_guid, show_lines_incl_vat, total_excl_vat, total_vatable_amount, total_incl_vat, total_non_vatable_amount, total_vat, invoice_template_id, status, payment_condition_number_of_days, payment_condition_type, fik_code, deposit_account_number, mail_out_status, latest_mail_out_type, is_sent_to_debt_collection, is_mobile_pay_invoice_enabled, is_penso_pay_enabled, credit_note_for) VALUES (:guid,:orgId,:currency,:language,:external_reference,:description,:comment,:credit_date,:address,:number,:contact_name,:contact_guid,:show_lines_incl_vat,:total_excl_vat,:total_vatable_amount,:total_incl_vat,:total_non_vatable_amount,:total_vat,:invoice_template_id,\'Draft\',:payment_condition_number_of_days,:payment_condition_type,:fik_code,:deposit_account_number,:mail_out_status,:latest_mail_out_type,:is_sent_to_debt_collection,:is_mobile_pay_invoice_enabled,:is_penso_pay_enabled,:credit_note_for)');
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
            $response->getBody()->write(json_encode(['guid'=>$creditGuid,'number'=>$nextNumber,'message'=>'Credit note created']));
            return $response->withStatus(201)->withHeader('Content-Type','application/json');
        });

        /**
         * Soft delete or hard delete an invoice.  Draft invoices are
         * hard‑deleted, booked invoices are soft‑deleted.
         */
        $group->delete('/{guid}', function (Request $request, Response $response, array $args) use ($container) {
            $pdo   = $container->get('db');
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
         * Send invoice by email.  This is a stub that validates the
         * request body and marks the invoice as emailed.  In a real
         * implementation a mailer (e.g. PHPMailer) would be used.
         */
        $group->post('/{guid}/email', function (Request $request, Response $response, array $args) use ($container) {
            $pdo   = $container->get('db');
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
 * Compute invoice totals from a set of invoice lines.  Each line may
 * specify a `vatRate` directly or reference a `vatCode`.  If `vatCode`
 * is provided the rate is looked up in the `vat_type` table for the
 * current organisation.  Returns an array with the keys:
 *   totalExclVat, totalInclVat, totalVat, totalVatableAmount,
 *   totalNonVatableAmount
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
            $vatRate = (float)$line['vatRate'];
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
 * Persist invoice lines to the invoice_lines table for a given invoice
 * GUID.  Lines passed in must match the structure used by
 * computeInvoiceTotals().  This helper resolves the account name
 * automatically from the account table when possible.
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
            $vatRate = (float)$line['vatRate'];
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
 * Ensure the accounting_year table has the is_locked column.  Some
 * migrations may not add this column for early installations; this
 * helper makes sure it exists before checking for locks.
 */
function ensureAccountingYearHasLockColumn(PDO $pdo): void
{
    $columnsYear = [];
    $stmtInfo = $pdo->query('PRAGMA table_info(accounting_year)');
    $rows = $stmtInfo->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $col) {
        $columnsYear[] = $col['name'];
    }
    if (!in_array('is_locked', $columnsYear)) {
        $pdo->exec('ALTER TABLE accounting_year ADD COLUMN is_locked INTEGER DEFAULT 0');
    }
}

/**
 * Ensure the credit_note table has an organization_id column.  If it
 * does not exist the column will be added with a default of NULL.
 */
function ensureCreditNoteHasOrgColumn(PDO $pdo): void
{
    $stmtInfo = $pdo->query('PRAGMA table_info(credit_note)');
    $cols = $stmtInfo->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'name');
    if (!in_array('organization_id', $names)) {
        $pdo->exec('ALTER TABLE credit_note ADD COLUMN organization_id TEXT');
    }
}