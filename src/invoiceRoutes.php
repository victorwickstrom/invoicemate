<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Psr\Container\ContainerInterface;


return function (App $app) {
    $container = $app->getContainer(); // Hämta DI-container

    $app->group('/v1/{organizationId}/invoices', function (RouteCollectorProxy $group) use ($container) {
        
        // Hämta alla fakturor
        $group->get('', function (Request $request, Response $response, array $args) use ($container) {
            return listInvoices($request, $response, $args, $container);
        });

        // Skapa en faktura
        $group->post('', function (Request $request, Response $response, array $args) use ($container) {
            return createInvoice($request, $response, $args, $container);
        });

        // Hämta faktura som JSON eller PDF
        $group->get('/{guid}', function (Request $request, Response $response, array $args) use ($container) {
            return getInvoiceDetails($request, $response, $args, $container);
        });

        // Hämta fakturatotaler
        $group->post('/fetch', function (Request $request, Response $response, array $args) use ($container) {
            return fetchInvoiceTotals($request, $response, $args, $container);
        });

        // Uppdatera en faktura
        $group->put('/{guid}', function (Request $request, Response $response, array $args) use ($container) {
            return updateInvoice($request, $response, $args, $container);
        });

        // Bokföra en faktura
        $group->post('/{guid}/book', function (Request $request, Response $response, array $args) use ($container) {
            return bookInvoice($request, $response, $args, $container);
        });

        // Lägg till en betalning på en faktura
        $group->post('/{guid}/payment', function (Request $request, Response $response, array $args) use ($container) {
            return addInvoicePayment($request, $response, $args, $container);
        });

        // Generera en kreditnota från en faktura
        $group->post('/{guid}/creditnote', function (Request $request, Response $response, array $args) use ($container) {
            return createCreditNote($request, $response, $args, $container);
        });

        // Ta bort en faktura
        $group->delete('/{guid}', function (Request $request, Response $response, array $args) use ($container) {
            return deleteInvoice($request, $response, $args, $container);
        });

        // Skicka faktura via e-post
        $group->post('/{guid}/email', function (Request $request, Response $response, array $args) use ($container) {
            return sendInvoiceEmail($request, $response, $args, $container);
        });
    });
};

// Funktioner
function listInvoices(Request $request, Response $response, array $args, ContainerInterface $container) {
    $pdo = $container->get('db');
    $params = $request->getQueryParams();
    
    $statusFilter = isset($params['statusFilter']) ? explode(',', $params['statusFilter']) : [];
    $queryFilter = $params['queryFilter'] ?? null;
    $freeTextSearch = $params['freeTextSearch'] ?? null;
    $startDate = $params['startDate'] ?? null;
    $endDate = $params['endDate'] ?? null;
    $page = isset($params['page']) ? max(0, (int) $params['page']) : 0;
    $pageSize = isset($params['pageSize']) ? min(1000, max(1, (int) $params['pageSize'])) : 100;
    $sort = isset($params['sort']) ? explode(',', $params['sort']) : ['number', 'invoice_date'];
    $sortOrder = isset($params['sortOrder']) && strtolower($params['sortOrder']) === 'ascending' ? 'ASC' : 'DESC';
    
    $query = "SELECT * FROM invoice WHERE organization_id = :organizationId";
    $bindings = ['organizationId' => $args['organizationId']];
    
    if (!empty($statusFilter)) {
        $query .= " AND status IN (" . implode(',', array_fill(0, count($statusFilter), '?')) . ")";
        $bindings = array_merge($bindings, $statusFilter);
    }
    if ($queryFilter) {
        $query .= " AND (external_reference LIKE :queryFilter OR contact_guid LIKE :queryFilter OR description LIKE :queryFilter)";
        $bindings['queryFilter'] = "%$queryFilter%";
    }
    if ($freeTextSearch) {
        $query .= " AND (number LIKE :freeText OR contact_name LIKE :freeText OR description LIKE :freeText OR total_incl_vat LIKE :freeText)";
        $bindings['freeText'] = "%$freeTextSearch%";
    }
    if ($startDate && $endDate) {
        $query .= " AND invoice_date BETWEEN :startDate AND :endDate";
        $bindings['startDate'] = $startDate;
        $bindings['endDate'] = $endDate;
    }
    
    $query .= " ORDER BY " . implode(',', $sort) . " $sortOrder";
    $query .= " LIMIT :limit OFFSET :offset";
    $bindings['limit'] = $pageSize;
    $bindings['offset'] = $page * $pageSize;

    $stmt = $pdo->prepare($query);
    $stmt->execute($bindings);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($invoices));
    return $response->withHeader('Content-Type', 'application/json');
}


// Funktion createInvoice enligt Dinero API och SQL-struktur
function createInvoice(Request $request, Response $response, array $args, ContainerInterface $container) {
    $pdo = $container->get('db');
    $data = json_decode($request->getBody()->getContents(), true);

    if (empty($data['productLines']) || !is_array($data['productLines'])) {
        $response->getBody()->write(json_encode(["error" => "productLines is required"]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $guid = $data['guid'] ?? Uuid::uuid4()->toString();
    $invoiceDate = $data['date'] ?? date('Y-m-d');

    $pdo = $container->get('db');

    // Determine next invoice number for organization to ensure sequential numbers
    $stmtNum = $pdo->prepare("SELECT COALESCE(MAX(\"number\"), 0) + 1 AS next_number FROM invoice WHERE organization_id = :orgId");
    $stmtNum->execute([':orgId' => $args['organizationId']]);
    $nextInvoiceNumber = (int) $stmtNum->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO invoice (guid, organization_id, currency, language, external_reference, description, comment, invoice_date, address, number, contact_name, show_lines_incl_vat, total_excl_vat, total_vatable_amount, total_incl_vat, total_non_vatable_amount, total_vat, invoice_template_id, status, contact_guid, payment_condition_number_of_days, payment_condition_type, reminder_fee, reminder_interest_rate, is_mobile_pay_invoice_enabled, is_penso_pay_enabled)
                            VALUES (:guid, :organization_id, :currency, :language, :external_reference, :description, :comment, :invoice_date, :address, :number, :contact_name, :show_lines_incl_vat, :total_excl_vat, :total_vatable_amount, :total_incl_vat, :total_non_vatable_amount, :total_vat, :invoice_template_id, 'Draft', :contact_guid, :payment_condition_number_of_days, :payment_condition_type, :reminder_fee, :reminder_interest_rate, :is_mobile_pay_invoice_enabled, :is_penso_pay_enabled)");

    $stmt->execute([
        'guid' => $guid,
        'organization_id' => $args['organizationId'],
        'currency' => $data['currency'] ?? 'DKK',
        'language' => $data['language'] ?? 'da-DK',
        'external_reference' => $data['externalReference'] ?? null,
        'description' => $data['description'] ?? null,
        'comment' => $data['comment'] ?? null,
        'invoice_date' => $data['date'] ?? date('Y-m-d'),
        'address' => $data['address'] ?? null,
        'number' => $nextInvoiceNumber,
        'contact_name' => $data['contactName'] ?? null,
        'show_lines_incl_vat' => $data['showLinesInclVat'] ? 1 : 0,
        'total_excl_vat' => 0,
        'total_vatable_amount' => 0,
        'total_incl_vat' => 0,
        'total_non_vatable_amount' => 0,
        'total_vat' => 0,
        'invoice_template_id' => $data['invoiceTemplateId'] ?? null,
        'contact_guid' => $data['contactGuid'] ?? null,
        'payment_condition_number_of_days' => $data['paymentConditionNumberOfDays'] ?? 14,
        'payment_condition_type' => $data['paymentConditionType'] ?? 'Netto',
        'reminder_fee' => $data['reminderFee'] ?? 0,
        'reminder_interest_rate' => $data['reminderInterestRate'] ?? 0,
        'is_mobile_pay_invoice_enabled' => $data['isMobilePayInvoiceEnabled'] ? 1 : 0,
        'is_penso_pay_enabled' => $data['isPensoPayEnabled'] ? 1 : 0
    ]);

    $response->getBody()->write(json_encode(["guid" => $guid, "message" => "Invoice created"]));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
}

// Funktion fetchInvoiceTotals enligt Dinero API och SQL-struktur
function fetchInvoiceTotals(Request $request, Response $response, array $args, ContainerInterface $container) {
    $data = json_decode($request->getBody()->getContents(), true);

    if (empty($data['productLines']) || !is_array($data['productLines'])) {
        $response->getBody()->write(json_encode(["error" => "productLines is required"]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $totalExclVat = 0;
    $totalVat = 0;
    $totalInclVat = 0;

    foreach ($data['productLines'] as $line) {
        $quantity = $line['quantity'] ?? 1;
        $unitPrice = $line['unitPrice'] ?? 0;
        $vatRate = $line['vatRate'] ?? 0.25;

        $lineTotalExclVat = $quantity * $unitPrice;
        $lineVatAmount = $lineTotalExclVat * $vatRate;
        $lineTotalInclVat = $lineTotalExclVat + $lineVatAmount;

        $totalExclVat += $lineTotalExclVat;
        $totalVat += $lineVatAmount;
        $totalInclVat += $lineTotalInclVat;
    }

    $result = [
        "currency" => $request->getParsedBody()['currency'] ?? 'DKK',
        "language" => $request->getParsedBody()['language'] ?? 'da-DK',
        "externalReference" => $data['externalReference'] ?? null,
        "description" => $data['description'] ?? null,
        "comment" => $data['comment'] ?? null,
        "date" => $data['date'] ?? date('Y-m-d'),
        "totalExclVat" => $totalExclVat,
        "totalVatableAmount" => $totalExclVat,
        "totalInclVat" => $totalInclVat,
        "totalNonVatableAmount" => 0,
        "totalVat" => $totalVat,
        "paymentConditionNumberOfDays" => $data['paymentConditionNumberOfDays'] ?? 14,
        "paymentConditionType" => $data['paymentConditionType'] ?? 'Netto',
        "canEnableMobilePayInvoice" => !empty($data['isMobilePayInvoiceEnabled']),
        "canEnablePensoPay" => $data['isPensoPayEnabled'] ?? false
    ];

    $response->getBody()->write(json_encode($invoice));
    return $response->withHeader('Content-Type', 'application/json');
}


// Funktion updateInvoice enligt Dinero API och SQL-struktur
function updateInvoice(Request $request, Response $response, array $args, ContainerInterface $container) {
    $pdo = $container->get('db');
    $data = json_decode($request->getBody()->getContents(), true);

    if (empty($data['productLines']) || !is_array($data['productLines'])) {
        $response->getBody()->write(json_encode(["error" => "productLines is required"]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $stmt = $pdo->prepare("UPDATE invoice SET currency = :currency, language = :language, external_reference = :external_reference, description = :description, comment = :comment, invoice_date = :invoice_date, address = :address, contact_name = :contact_name, show_lines_incl_vat = :show_lines_incl_vat, invoice_template_id = :invoice_template_id, contact_guid = :contact_guid, payment_condition_number_of_days = :payment_condition_number_of_days, payment_condition_type = :payment_condition_type, reminder_fee = :reminder_fee, reminder_interest_rate = :reminder_interest_rate, is_mobile_pay_invoice_enabled = :is_mobile_pay_invoice_enabled, is_penso_pay_enabled = :is_penso_pay_enabled, updated_at = CURRENT_TIMESTAMP WHERE organization_id = :organizationId AND guid = :guid");

    $stmt->execute([
        'currency' => $data['currency'] ?? 'DKK',
        'language' => $data['language'] ?? 'da-DK',
        'external_reference' => $data['externalReference'] ?? null,
        'description' => $data['description'] ?? null,
        'comment' => $data['comment'] ?? null,
        'invoice_date' => $data['date'] ?? date('Y-m-d'),
        'address' => $data['address'] ?? null,
        'contact_guid' => $data['contactGuid'] ?? null,
        'payment_condition_number_of_days' => $data['paymentConditionNumberOfDays'] ?? 14,
        'payment_condition_type' => $data['paymentConditionType'] ?? 'Netto',
        'reminder_fee' => $data['reminderFee'] ?? 0,
        'reminder_interest_rate' => $data['reminderInterestRate'] ?? 0,
        'is_mobile_pay_invoice_enabled' => $data['isMobilePayInvoiceEnabled'] ? 1 : 0,
        'is_penso_pay_enabled' => $data['isPensoPayEnabled'] ? 1 : 0,
        'language' => $data['language'] ?? 'da-DK',
        'payment_condition_number_of_days' => $data['paymentConditionNumberOfDays'] ?? 14,
        'guid' => $args['guid'],
        'organization_id' => $args['organizationId']
    ]);

    $stmt->execute();

    $timeStamp = time();

    $response->getBody()->write(json_encode(["guid" => $args['guid'], "timeStamp" => (string)$timeStamp]));
    return $response->withHeader('Content-Type', 'application/json');
}

// Funktion bookInvoice för att bokföra en faktura
function bookInvoice(Request $request, Response $response, array $args, ContainerInterface $container) {
    $pdo = $container->get('db');

    $organizationId = $args['organizationId'];
    $invoiceGuid     = $args['guid'];

    // Role-based access control: only admin may book invoices
    $user = $request->getAttribute('user');
    $roles = $user['roles'] ?? [];
    if (!in_array('admin', $roles)) {
        $response->getBody()->write(json_encode(['error' => 'Forbidden: insufficient role']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }

    // Kontrollera om fakturan existerar och inte redan är bokförd
    $stmt = $pdo->prepare("SELECT guid, number, status, invoice_date FROM invoice WHERE organization_id = :organizationId AND guid = :guid");
    $stmt->execute([
        'organizationId' => $organizationId,
        'guid' => $invoiceGuid
    ]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        $response->getBody()->write(json_encode(["error" => "Invoice not found"]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    if ($invoice['status'] !== 'Draft') {
        $response->getBody()->write(json_encode(["error" => "Only draft invoices can be booked"]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // Kontrollera låst period (periodlåsning)
    $invoiceDate = $invoice['invoice_date'];
    if ($invoiceDate) {
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
        $stmtLocked = $pdo->prepare("SELECT COUNT(*) FROM accounting_year WHERE organization_id = :orgId AND is_locked = 1 AND from_date <= :date AND to_date >= :date");
        $stmtLocked->execute([':orgId' => $organizationId, ':date' => $invoiceDate]);
        $isLocked = (int) $stmtLocked->fetchColumn() > 0;
        if ($isLocked) {
            $response->getBody()->write(json_encode(["error" => 'Accounting period is locked for date ' . $invoiceDate]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    // Hämta alla rader för fakturan
    $stmtLines = $pdo->prepare("SELECT account_number, description, total_amount, total_amount_incl_vat FROM invoice_lines WHERE invoice_guid = :guid");
    $stmtLines->execute([':guid' => $invoiceGuid]);
    $invoiceLines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
    if (!$invoiceLines) {
        $response->getBody()->write(json_encode(["error" => "Invoice has no lines to book"]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // Create journal entries for each line: debit accounts receivable (1100), credit revenue and VAT (2610)
    $entries = [];
    foreach ($invoiceLines as $line) {
        $totalIncl = (float) $line['total_amount_incl_vat'];
        $totalExcl = (float) $line['total_amount'];
        $vatAmount = $totalIncl - $totalExcl;
        // Debit accounts receivable (1100)
        $entries[] = [
            'account_number' => 1100,
            'description' => 'Invoice ' . $invoice['number'] . ' ' . ($line['description'] ?? ''),
            'amount' => $totalIncl
        ];
        // Credit revenue account
        $entries[] = [
            'account_number' => (int) $line['account_number'],
            'description' => 'Invoice ' . $invoice['number'] . ' ' . ($line['description'] ?? ''),
            'amount' => -$totalExcl
        ];
        // Credit VAT if applicable
        if (abs($vatAmount) > 0.001) {
            $entries[] = [
                'account_number' => 2610,
                'description' => 'VAT for invoice ' . $invoice['number'],
                'amount' => -$vatAmount
            ];
        }
    }
    // Verify that entries balance
    $sumEntries = 0.0;
    foreach ($entries as $ent) {
        $sumEntries += (float) $ent['amount'];
    }
    if (abs($sumEntries) > 0.001) {
        // Not balanced
        $response->getBody()->write(json_encode(["error" => "Invoice entries are not balanced", 'sum' => $sumEntries]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // Begin transaction: update invoice status and insert entries
    $pdo->beginTransaction();
    try {
        // Uppdatera fakturans status till "Booked"
        $stmtUpd = $pdo->prepare("UPDATE invoice SET status = 'Booked', updated_at = CURRENT_TIMESTAMP WHERE organization_id = :organizationId AND guid = :guid");
        $stmtUpd->execute([
            'organizationId' => $organizationId,
            'guid' => $invoiceGuid
        ]);

        // Insert journal entries
        foreach ($entries as $ent) {
            $entryGuid = uniqid('entry_', true);
            $stmtEntry = $pdo->prepare('INSERT INTO entries (organization_id, account_number, account_name, entry_date, voucher_number, voucher_type, description, vat_type, vat_code, amount, entry_guid, contact_guid, entry_type) VALUES (:orgId, :accountNumber, NULL, :entryDate, :voucherNumber, :voucherType, :description, NULL, NULL, :amount, :entryGuid, NULL, :entryType)');
            $stmtEntry->execute([
                ':orgId' => $organizationId,
                ':accountNumber' => $ent['account_number'],
                ':entryDate' => $invoiceDate ?? date('Y-m-d'),
                ':voucherNumber' => $invoice['number'],
                ':voucherType' => 'Invoice',
                ':description' => $ent['description'],
                ':amount' => $ent['amount'],
                ':entryGuid' => $entryGuid,
                ':entryType' => 'Normal'
            ]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        $response->getBody()->write(json_encode(["error" => 'Booking invoice failed', 'details' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    // Insert audit log
    $user   = $request->getAttribute('user');
    $userId = $user['user_id'] ?? null;
    $logStmt = $pdo->prepare("INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data) VALUES (:orgId, :userId, :tableName, :recordId, :operation, :changedData)");
    $logStmt->execute([
        ':orgId'       => $organizationId,
        ':userId'      => $userId,
        ':tableName'   => 'invoice',
        ':recordId'    => $invoiceGuid,
        ':operation'   => 'BOOK',
        ':changedData' => json_encode($entries)
    ]);

    $response->getBody()->write(json_encode(["guid" => $invoiceGuid, "message" => "Invoice booked", "voucherNumber" => $invoice['number']]));
    return $response->withHeader('Content-Type', 'application/json');
}

// Funktion addInvoicePayment för att lägga till en betalning
function addInvoicePayment(Request $request, Response $response, array $args, ContainerInterface $container) {
    $pdo = $container->get('db');
    $data = json_decode($request->getBody()->getContents(), true);

    if (empty($data['amount']) || $data['amount'] == 0) {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode(["error" => "Payment amount must be different from 0"]));
    }

    // Kontrollera att fakturan existerar och är bokförd
    $stmt = $pdo->prepare("SELECT status FROM invoice WHERE organization_id = :organizationId AND guid = :guid");
    $stmt->execute([
        'organizationId' => $args['organizationId'],
        'guid' => $args['guid']
    ]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode(["error" => "Invoice not found"]));
    }

    if ($invoice['status'] !== 'Booked') {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode(["error" => "Only booked invoices can receive payments"]));
    }

    // Lägg till betalningen
    $stmt = $pdo->prepare("INSERT INTO payments (invoice_guid, payment_date, payment_amount, payment_method, comments) VALUES (:invoice_guid, :payment_date, :payment_amount, :payment_method, :comments)");
    $stmt->execute([
        'invoice_guid' => $args['guid'],
        'payment_date' => $data['paymentDate'] ?? date('Y-m-d'),
        'payment_amount' => $data['amount'],
        'payment_method' => $data['paymentMethod'] ?? 'unknown',
        'comments' => $data['description'] ?? null
    ]);

    $response->getBody()->write(json_encode(["guid" => $args['guid'], "message" => "Payment added"]));
    return $response->withHeader('Content-Type', 'application/json');
}


// Funktion createCreditNote för att generera en kreditnota
function createCreditNote(Request $request, Response $response, array $args, ContainerInterface $container) {
    $pdo = $container->get('db');

    // Hämta fakturan
    $stmt = $pdo->prepare("SELECT * FROM invoice WHERE organization_id = :organizationId AND guid = :guid");
    $stmt->execute([
        'organizationId' => $args['organizationId'],
        'guid' => $args['guid']
    ]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode(["error" => "Invoice not found"]));
    }

    // Skapa kreditnota
    $creditNoteGuid = Uuid::uuid4()->toString();
    $stmt = $pdo->prepare("INSERT INTO credit_note (guid, currency, language, external_reference, description, comment, credit_note_date, address, number, contact_name, contact_guid, show_lines_incl_vat, total_excl_vat, total_vatable_amount, total_incl_vat, total_non_vatable_amount, total_vat, invoice_template_id, status, payment_condition_number_of_days, payment_condition_type, fik_code, deposit_account_number, mail_out_status, latest_mail_out_type, is_sent_to_debt_collection, is_mobile_pay_invoice_enabled, is_penso_pay_enabled, credit_note_for)
                            VALUES (:guid, :currency, :language, :external_reference, :description, :comment, :credit_note_date, :address, :number, :contact_name, :contact_guid, :show_lines_incl_vat, :total_excl_vat, :total_vatable_amount, :total_incl_vat, :total_non_vatable_amount, :total_vat, :invoice_template_id, 'Draft', :payment_condition_number_of_days, :payment_condition_type, :fik_code, :deposit_account_number, :mail_out_status, :latest_mail_out_type, :is_sent_to_debt_collection, :is_mobile_pay_invoice_enabled, :is_penso_pay_enabled, :credit_note_for)");
    
    $stmt->execute([
        'guid' => $creditNoteGuid,
        'currency' => $invoice['currency'],
        'language' => $invoice['language'],
        'external_reference' => $invoice['external_reference'],
        'description' => $invoice['description'],
        'comment' => $invoice['comment'],
        'credit_note_date' => date('Y-m-d'),
        'address' => $invoice['address'],
        'number' => rand(1000,9999),
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
        'is_sent_to_debt_collection' => $invoice['is_sent_to_debt_collection'],
        'is_mobile_pay_invoice_enabled' => $invoice['is_mobile_pay_invoice_enabled'],
        'is_penso_pay_enabled' => $invoice['is_penso_pay_enabled'],
        'credit_note_for' => $args['guid']
    ]);

    $response->getBody()->write(json_encode(["guid" => $creditNoteGuid, "message" => "Credit note created"]));
    return $response->withHeader('Content-Type', 'application/json');
}



// Funktion deleteInvoice för att ta bort en faktura
function deleteInvoice(Request $request, Response $response, array $args, ContainerInterface $container) {
    $pdo = $container->get('db');

    // Kontrollera om fakturan existerar
    $stmt = $pdo->prepare("SELECT status FROM invoice WHERE organization_id = :organizationId AND guid = :guid");
    $stmt->execute([
        'organizationId' => $args['organizationId'],
        'guid' => $args['guid']
    ]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode(["error" => "Invoice not found"]));
    }

    if ($invoice['status'] !== 'Draft') {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode(["error" => "Only draft invoices can be deleted"]));
    }

    // Mjukradera fakturan genom att sätta deleted_at
    $stmt = $pdo->prepare("UPDATE invoice SET deleted_at = CURRENT_TIMESTAMP WHERE organization_id = :organizationId AND guid = :guid");
    $stmt->execute([
        'organizationId' => $args['organizationId'],
        'guid' => $args['guid']
    ]);

    $response->getBody()->write(json_encode(["guid" => $args['guid'], "message" => "Invoice deleted"]));
    return $response->withHeader('Content-Type', 'application/json');
}

// Funktion attachInvoiceFile för att bifoga en fil till en faktura
function attachInvoiceFile(Request $request, Response $response, array $args, ContainerInterface $container) {
    $directory = __DIR__ . '/uploads';
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $uploadedFiles = $request->getUploadedFiles();
    if (empty($uploadedFiles['file'])) {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode(["error" => "No file uploaded"]));
    }

    $file = $uploadedFiles['file'];
    if ($file->getError() !== UPLOAD_ERR_OK) {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode(["error" => "File upload error"]));
    }

    $filename = sprintf('%s_%s', $args['guid'], $file->getClientFilename());
    $filePath = $directory . DIRECTORY_SEPARATOR . $filename;
    $file->moveTo($filePath);

    $response->getBody()->write(json_encode(["guid" => $args['guid'], "message" => "File attached successfully", "filename" => $filename]));
    return $response->withHeader('Content-Type', 'application/json');
}

// Funktion sendInvoiceEmail för att skicka faktura via e-post
function sendInvoiceEmail(Request $request, Response $response, array $args, ContainerInterface $container) {
    $data = json_decode($request->getBody()->getContents(), true);
    
    $requiredFields = ['receiver', 'subject', 'message', 'shouldAddTrustPilotEmailAsBcc'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->getBody()->write(json_encode(["error" => "$field is required"]));
        }
    }
    
    // Simulerad e-postskickning
    $emailSent = true; // Här kan du implementera riktig e-postsändning

    if (!$emailSent) {
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode(["error" => "Failed to send email"]));
    }

    $response->getBody()->write(json_encode([
        "guid" => $args['guid'],
        "message" => "Email sent successfully",
        "recipients" => explode(',', $data['receiver'])
    ]));
    return $response->withHeader('Content-Type', 'application/json');
}

// Funktion sendElectronicInvoice för att skicka en elektronisk faktura
function sendElectronicInvoice(Request $request, Response $response, array $args, ContainerInterface $container) {
    $pdo = $container->get('db');
    $data = json_decode($request->getBody()->getContents(), true);
    
    $requiredFields = ['paymentMeanEnum'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->getBody()->write(json_encode(["error" => "$field is required"]));
        }
    }
    
    // Kontrollera om fakturan existerar och är bokförd
    $stmt = $pdo->prepare("SELECT status FROM invoice WHERE organization_id = :organizationId AND guid = :guid");
    $stmt->execute([
        'organizationId' => $args['organizationId'],
        'guid' => $args['guid']
    ]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode(["error" => "Invoice not found"]));
    }

    if ($invoice['status'] !== 'Booked') {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode(["error" => "Only booked invoices can be sent electronically"]));
    }

    // Skicka den elektroniska fakturan (Simulerad sändning - här kan API-anrop implementeras)
    $electronicInvoiceSent = true; // Byt ut detta mot riktig e-faktura integration

    if (!$electronicInvoiceSent) {
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode(["error" => "Failed to send electronic invoice"]));
    }

    // Returnera bekräftelse
    $response->getBody()->write(json_encode([
        "guid" => $args['guid'],
        "message" => "Electronic invoice sent successfully",
        "paymentMean" => $data['paymentMeanEnum'],
        "orderReference" => $data['orderReference'] ?? null,
        "attPerson" => $data['attPerson'] ?? null
    ]));
    return $response->withHeader('Content-Type', 'application/json');
}



