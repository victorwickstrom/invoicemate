<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Psr\Container\ContainerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * SAF‑T export routes.
 *
 * This file defines a new route group under `/v1/{organizationId}/saft` that
 * exposes a GET endpoint `/export` for generating and exporting a SAF‑T XML
 * document. When called, the endpoint retrieves accounts, contacts and
 * ledger entries from the SQLite database for the specified organization
 * (optionally filtered by a date range) and constructs an XML document
 * conforming to the Danish SAF‑T structure. The resulting XML is returned
 * as a downloadable file.
 */
return function (App $app) {
    $container = $app->getContainer();

    $app->group('/v1/{organizationId}/saft', function (RouteCollectorProxy $group) use ($container) {
        // GET /v1/{organizationId}/saft/export
        $group->get('/export', function (Request $request, Response $response, array $args) use ($container) {
            return exportSaft($request, $response, $args, $container);
        });
    });
};

/**
 * Generate and return a SAF‑T XML file for a given organization.
 *
 * This handler reads the query parameters `from` and `to` to optionally
 * constrain the exported period. It then selects all relevant accounts,
 * contacts (customers and suppliers) and entries from the database,
 * constructs a minimal SAF‑T compliant XML document and returns it as
 * an attachment. Errors are logged via the configured logger or stderr.
 *
 * @param Request            $request  The incoming HTTP request
 * @param Response           $response The outgoing HTTP response
 * @param array              $args     Route parameters (contains organizationId)
 * @param ContainerInterface $container Dependency injection container
 *
 * @return Response Modified response with XML content or error JSON
 */
function exportSaft(Request $request, Response $response, array $args, ContainerInterface $container): Response
{
    // Check for database connection
    if (!$container->has('db')) {
        $response->getBody()->write(json_encode(['error' => 'Database connection not found']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    $pdo = $container->get('db');

    // Resolve logger
    if ($container->has('logger')) {
        $logger = $container->get('logger');
    } else {
        $logger = new Logger('saft_export');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));
    }

    $organizationId = $args['organizationId'];
    $queryParams = $request->getQueryParams();
    $fromDate = isset($queryParams['from']) && trim($queryParams['from']) !== '' ? $queryParams['from'] : null;
    $toDate   = isset($queryParams['to']) && trim($queryParams['to']) !== '' ? $queryParams['to'] : null;

    // Validate date format (YYYY-MM-DD). If invalid, return error
    $dateRegex = '/^\d{4}-\d{2}-\d{2}$/';
    if ($fromDate && !preg_match($dateRegex, $fromDate)) {
        $msg = 'Invalid from date format. Expected YYYY-MM-DD.';
        $logger->warning($msg);
        $response->getBody()->write(json_encode(['error' => $msg]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    if ($toDate && !preg_match($dateRegex, $toDate)) {
        $msg = 'Invalid to date format. Expected YYYY-MM-DD.';
        $logger->warning($msg);
        $response->getBody()->write(json_encode(['error' => $msg]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        // Fetch all active accounts
        $stmtAccounts = $pdo->prepare('SELECT accountNumber, name, vatCode FROM account WHERE isActive = 1');
        $stmtAccounts->execute();
        $accounts = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);

        // Fetch contacts for organization: customers and suppliers
        $stmtCustomers = $pdo->prepare('SELECT * FROM contacts WHERE organization_id = :orgId AND is_debitor = 1 AND deleted_at IS NULL');
        $stmtCustomers->execute(['orgId' => $organizationId]);
        $customers = $stmtCustomers->fetchAll(PDO::FETCH_ASSOC);
        $stmtSuppliers = $pdo->prepare('SELECT * FROM contacts WHERE organization_id = :orgId AND is_creditor = 1 AND deleted_at IS NULL');
        $stmtSuppliers->execute(['orgId' => $organizationId]);
        $suppliers = $stmtSuppliers->fetchAll(PDO::FETCH_ASSOC);

        // Fetch ledger entries for organization, optionally filtered by date range
        $query = 'SELECT * FROM entries WHERE organization_id = :orgId';
        $params = ['orgId' => $organizationId];
        if ($fromDate) {
            $query .= ' AND entry_date >= :fromDate';
            $params['fromDate'] = $fromDate;
        }
        if ($toDate) {
            $query .= ' AND entry_date <= :toDate';
            $params['toDate'] = $toDate;
        }
        $query .= ' ORDER BY voucher_number, id';
        $stmtEntries = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmtEntries->bindValue(':' . $key, $value);
        }
        $stmtEntries->execute();
        $entries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);

        // Create XML document
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><AuditFile></AuditFile>');

        // Build header information
        $header = $xml->addChild('Header');
        $header->addChild('AuditFileVersion', '1.0');
        $header->addChild('AuditFileCountry', 'DK');
        $header->addChild('AuditFileDateCreated', date('Y-m-d'));
        // Fetch company details from organizations table
        $stmtOrg = $pdo->prepare('SELECT vat_number, name, street, city, zip_code, country_key FROM organizations WHERE id = :id');
        $stmtOrg->execute([':id' => $organizationId]);
        $org = $stmtOrg->fetch(PDO::FETCH_ASSOC);
        $company = $header->addChild('Company');
        $company->addChild('RegistrationNumber', htmlspecialchars($org['vat_number'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        $company->addChild('Name', htmlspecialchars($org['name'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        // Optional company address
        $companyAddress = $company->addChild('CompanyAddress');
        $companyAddress->addChild('StreetName', htmlspecialchars($org['street'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        $companyAddress->addChild('City', htmlspecialchars($org['city'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        $companyAddress->addChild('PostalCode', htmlspecialchars($org['zip_code'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        $companyAddress->addChild('Country', htmlspecialchars($org['country_key'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        // Selection criteria (period covered)
        $selection = $header->addChild('SelectionCriteria');
        if ($fromDate) {
            $selection->addChild('SelectionStartDate', $fromDate);
        }
        if ($toDate) {
            $selection->addChild('SelectionEndDate', $toDate);
        }

        // Build master files
        $master = $xml->addChild('MasterFiles');
        // Accounts
        $glAccounts = $master->addChild('GeneralLedgerAccounts');
        foreach ($accounts as $acc) {
            $accNode = $glAccounts->addChild('Account');
            $accNode->addChild('AccountID', (string) $acc['accountNumber']);
            $accNode->addChild('AccountDescription', htmlspecialchars($acc['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            if (!empty($acc['vatCode'])) {
                $accNode->addChild('VATCode', htmlspecialchars($acc['vatCode'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            }
        }
        // Customers
        foreach ($customers as $contact) {
            $custNode = $master->addChild('Customer');
            $custNode->addChild('CustomerID', htmlspecialchars($contact['contact_guid'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $custNode->addChild('CustomerName', htmlspecialchars($contact['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $addressNode = $custNode->addChild('BillingAddress');
            $addressNode->addChild('StreetName', htmlspecialchars($contact['street'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $addressNode->addChild('City', htmlspecialchars($contact['city'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $addressNode->addChild('PostalCode', htmlspecialchars($contact['zip_code'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $addressNode->addChild('Country', htmlspecialchars($contact['country_key'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        }
        // Suppliers
        foreach ($suppliers as $contact) {
            $supNode = $master->addChild('Supplier');
            $supNode->addChild('SupplierID', htmlspecialchars($contact['contact_guid'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $supNode->addChild('SupplierName', htmlspecialchars($contact['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $addressNode = $supNode->addChild('BillingAddress');
            $addressNode->addChild('StreetName', htmlspecialchars($contact['street'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $addressNode->addChild('City', htmlspecialchars($contact['city'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $addressNode->addChild('PostalCode', htmlspecialchars($contact['zip_code'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $addressNode->addChild('Country', htmlspecialchars($contact['country_key'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        }
        // VAT Tax table
        $stmtVat = $pdo->prepare('SELECT vat_code, vat_rate FROM vat_type');
        $stmtVat->execute();
        $vatTypes = $stmtVat->fetchAll(PDO::FETCH_ASSOC);
        if ($vatTypes) {
            $taxTable = $master->addChild('TaxTable');
            foreach ($vatTypes as $vt) {
                $taxCode = $taxTable->addChild('TaxCode');
                $taxCode->addChild('TaxCodeID', htmlspecialchars($vt['vat_code'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $taxCode->addChild('Description', htmlspecialchars($vt['vat_code'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $taxCode->addChild('TaxType', 'VAT');
                $taxCode->addChild('TaxPercentage', number_format((float) $vt['vat_rate'], 2, '.', ''));
            }
        }

        // Build ledger entries
        $glEntries = $xml->addChild('GeneralLedgerEntries');
        // Create a single journal for all transactions
        $journal = $glEntries->addChild('Journal');
        $journal->addChild('JournalID', '1');
        // Group entries by voucher_number (or entry_guid if voucher_number null)
        $entryGroups = [];
        foreach ($entries as $entry) {
            $transId = $entry['voucher_number'] ?? $entry['entry_guid'];
            if (!isset($entryGroups[$transId])) {
                $entryGroups[$transId] = [];
            }
            $entryGroups[$transId][] = $entry;
        }
        foreach ($entryGroups as $transId => $group) {
            $transaction = $journal->addChild('Transaction');
            $transaction->addChild('TransactionID', htmlspecialchars((string) $transId, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            // Use date from first entry as transaction date
            $firstEntry = $group[0];
            if (!empty($firstEntry['entry_date'])) {
                $transaction->addChild('TransactionDate', htmlspecialchars($firstEntry['entry_date'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            }
            // Use description from first entry as transaction description
            if (!empty($firstEntry['description'])) {
                $transaction->addChild('Description', htmlspecialchars($firstEntry['description'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            }
            foreach ($group as $entry) {
                $line = $transaction->addChild('Line');
                $line->addChild('RecordID', htmlspecialchars($entry['entry_guid'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $line->addChild('AccountID', htmlspecialchars((string) $entry['account_number'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                if (!empty($entry['description'])) {
                    $line->addChild('Description', htmlspecialchars($entry['description'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                }
                $amount = (float) $entry['amount'];
                if ($amount >= 0) {
                    $line->addChild('DebitAmount', number_format($amount, 2, '.', ''));
                } else {
                    $line->addChild('CreditAmount', number_format(abs($amount), 2, '.', ''));
                }
            }
        }

        /**
         * SourceDocuments section: include SalesInvoices, PurchaseInvoices and Payments
         * The Danish SAF-T specification requires the listing of source documents that
         * correspond to the transactions in the general ledger. We gather
         * invoices, purchase vouchers and payments within the selected period
         * (if any) and append them under the SourceDocuments element.
         */
        $sourceDocs = $xml->addChild('SourceDocuments');
        // SalesInvoices
        $salesInvoicesNode = $sourceDocs->addChild('SalesInvoices');
        // Fetch invoices within date range
        $sqlInv = 'SELECT * FROM invoice WHERE organization_id = :orgId AND deleted_at IS NULL';
        $invParams = [':orgId' => $organizationId];
        if ($fromDate) {
            $sqlInv .= ' AND invoice_date >= :fromDate';
            $invParams[':fromDate'] = $fromDate;
        }
        if ($toDate) {
            $sqlInv .= ' AND invoice_date <= :toDate';
            $invParams[':toDate'] = $toDate;
        }
        $stmtInv = $pdo->prepare($sqlInv);
        $stmtInv->execute($invParams);
        $invoicesList = $stmtInv->fetchAll(PDO::FETCH_ASSOC);
        if ($invoicesList) {
            foreach ($invoicesList as $inv) {
                $invNode = $salesInvoicesNode->addChild('Invoice');
                // InvoiceNo and type
                $invNode->addChild('InvoiceNo', htmlspecialchars((string) $inv['number'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $invNode->addChild('InvoiceDate', htmlspecialchars($inv['invoice_date'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $invNode->addChild('InvoiceType', 'FT');
                // CustomerID: link to contact
                $custId = $inv['contact_guid'] ?? '';
                $invNode->addChild('CustomerID', htmlspecialchars((string) $custId, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                // Lines
                $linesNode = $invNode->addChild('LineItems');
                // Fetch invoice lines
                $stmtLine = $pdo->prepare('SELECT * FROM invoice_lines WHERE invoice_guid = :guid');
                $stmtLine->execute([':guid' => $inv['guid']]);
                $linesData = $stmtLine->fetchAll(PDO::FETCH_ASSOC);
                $lineNumber = 1;
                foreach ($linesData as $line) {
                    $lineNode = $linesNode->addChild('Line');
                    $lineNode->addChild('LineNumber', $lineNumber++);
                    $lineNode->addChild('Description', htmlspecialchars($line['description'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    // Use account_number as ProductCode for simplicity
                    $lineNode->addChild('ProductCode', htmlspecialchars((string) ($line['account_number'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $lineNode->addChild('Quantity', number_format((float) ($line['quantity'] ?? 1), 2, '.', ''));
                    $unitPrice = isset($line['unit_price']) ? (float) $line['unit_price'] : 0;
                    $lineNode->addChild('UnitPrice', number_format($unitPrice, 2, '.', ''));
                    // Tax details
                    $vatRate = isset($line['vat_rate']) ? (float) $line['vat_rate'] : 0;
                    $taxNode = $lineNode->addChild('Tax');
                    if ($vatRate > 0) {
                        $taxNode->addChild('TaxType', 'VAT');
                        $taxNode->addChild('TaxPercentage', number_format($vatRate * 100, 2, '.', ''));
                    } else {
                        $taxNode->addChild('TaxType', 'None');
                        $taxNode->addChild('TaxPercentage', '0.00');
                    }
                    // Line totals: net and gross
                    $netAmount = isset($line['total_amount']) ? (float) $line['total_amount'] : 0;
                    $grossAmount = isset($line['total_amount_incl_vat']) ? (float) $line['total_amount_incl_vat'] : $netAmount;
                    $lineNode->addChild('UnitPriceGross', number_format($grossAmount, 2, '.', ''));
                    $lineNode->addChild('CreditAmount', number_format($grossAmount, 2, '.', ''));
                }
                // Document totals
                $docTotals = $invNode->addChild('DocumentTotals');
                $docTotals->addChild('TaxPayable', number_format((float) ($inv['total_vat'] ?? 0), 2, '.', ''));
                $docTotals->addChild('NetTotal', number_format((float) ($inv['total_excl_vat'] ?? 0), 2, '.', ''));
                $docTotals->addChild('GrossTotal', number_format((float) ($inv['total_incl_vat'] ?? 0), 2, '.', ''));
            }
        }
        // PurchaseInvoices
        $purchaseInvoicesNode = $sourceDocs->addChild('PurchaseInvoices');
        $sqlPV = 'SELECT * FROM purchase_voucher WHERE 1=1';
        $pvParams = [];
        if ($fromDate) {
            $sqlPV .= ' AND voucher_date >= :pvFrom';
            $pvParams[':pvFrom'] = $fromDate;
        }
        if ($toDate) {
            $sqlPV .= ' AND voucher_date <= :pvTo';
            $pvParams[':pvTo'] = $toDate;
        }
        $stmtPV = $pdo->prepare($sqlPV);
        $stmtPV->execute($pvParams);
        $purchaseVouchers = $stmtPV->fetchAll(PDO::FETCH_ASSOC);
        foreach ($purchaseVouchers as $pv) {
            $piNode = $purchaseInvoicesNode->addChild('Invoice');
            $piNode->addChild('InvoiceNo', htmlspecialchars((string) ($pv['voucher_number'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $piNode->addChild('InvoiceDate', htmlspecialchars($pv['voucher_date'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $piNode->addChild('InvoiceType', 'PE');
            $supplierId = $pv['contact_guid'] ?? '';
            $piNode->addChild('SupplierID', htmlspecialchars((string) $supplierId, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            // Lines from purchase_voucher_line
            $lineItems = $pdo->prepare('SELECT * FROM purchase_voucher_line WHERE purchase_voucher_guid = :guid');
            $lineItems->execute([':guid' => $pv['guid']]);
            $linesData = $lineItems->fetchAll(PDO::FETCH_ASSOC);
            $linesNode = $piNode->addChild('LineItems');
            $lnr = 1;
            foreach ($linesData as $line) {
                $lineNode = $linesNode->addChild('Line');
                $lineNode->addChild('LineNumber', $lnr++);
                $lineNode->addChild('Description', htmlspecialchars($line['description'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                // Use account_number as ProductCode
                $lineNode->addChild('ProductCode', htmlspecialchars((string) ($line['account_number'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $lineNode->addChild('Quantity', number_format((float) ($line['quantity'] ?? 1), 2, '.', ''));
                $unitPrice = (float) ($line['base_amount_value'] ?? 0);
                $lineNode->addChild('UnitPrice', number_format($unitPrice, 2, '.', ''));
                $vatRate = (float) ($line['vat_rate'] ?? 0);
                $taxNode = $lineNode->addChild('Tax');
                if ($vatRate > 0) {
                    $taxNode->addChild('TaxType', 'VAT');
                    $taxNode->addChild('TaxPercentage', number_format($vatRate * 100, 2, '.', ''));
                } else {
                    $taxNode->addChild('TaxType', 'None');
                    $taxNode->addChild('TaxPercentage', '0.00');
                }
                $grossAmount = (float) ($line['total_amount_incl_vat'] ?? ($line['base_amount_value_incl_vat'] ?? 0));
                $lineNode->addChild('UnitPriceGross', number_format($grossAmount, 2, '.', ''));
                // Purchase invoice: treat as debit (positive) amount
                $lineNode->addChild('DebitAmount', number_format($grossAmount, 2, '.', ''));
            }
            // Document totals
            $docTotals = $piNode->addChild('DocumentTotals');
            $docTotals->addChild('TaxPayable', number_format((float) ($pv['total_vat'] ?? 0), 2, '.', ''));
            $docTotals->addChild('NetTotal', number_format((float) ($pv['total_amount'] ?? 0), 2, '.', ''));
            $docTotals->addChild('GrossTotal', number_format((float) ($pv['total_amount_incl_vat'] ?? 0), 2, '.', ''));
        }
        // Payments
        $paymentsNode = $sourceDocs->addChild('Payments');
        // Payments only relate to invoices
        $sqlPay = 'SELECT * FROM payments WHERE 1=1';
        $payParams = [];
        if ($fromDate) {
            $sqlPay .= ' AND payment_date >= :payFrom';
            $payParams[':payFrom'] = $fromDate;
        }
        if ($toDate) {
            $sqlPay .= ' AND payment_date <= :payTo';
            $payParams[':payTo'] = $toDate;
        }
        $stmtPay = $pdo->prepare($sqlPay);
        $stmtPay->execute($payParams);
        $payments = $stmtPay->fetchAll(PDO::FETCH_ASSOC);
        foreach ($payments as $payment) {
            $payNode = $paymentsNode->addChild('Payment');
            // Payment identification: use payment id
            $payNode->addChild('PaymentID', htmlspecialchars((string) $payment['id'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $payNode->addChild('PaymentDate', htmlspecialchars($payment['payment_date'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            // Reference invoice
            $payNode->addChild('SourceDocumentID', htmlspecialchars($payment['invoice_guid'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $payNode->addChild('Amount', number_format((float) $payment['payment_amount'], 2, '.', ''));
            $payNode->addChild('PaymentMethod', htmlspecialchars($payment['payment_method'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            if (!empty($payment['comments'])) {
                $payNode->addChild('Comments', htmlspecialchars($payment['comments'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            }
        }

        // Convert XML to string
        $xmlContent = $xml->asXML();
        // Build filename: SAFT_DK_{orgId}_{fromDate}_{toDate}_{uniqueId}.xml
        $fromPart = $fromDate ? str_replace('-', '', $fromDate) : '';
        $toPart   = $toDate ? str_replace('-', '', $toDate) : '';
        $uniqueId = uniqid();
        $filename = 'SAFT_DK_' . $organizationId . '_';
        $filename .= ($fromPart !== '' ? $fromPart : '');
        $filename .= '_';
        $filename .= ($toPart !== '' ? $toPart : '');
        $filename .= '_' . $uniqueId . '.xml';

        $response->getBody()->write($xmlContent);
        return $response->withHeader('Content-Type', 'application/xml')
                        ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    } catch (\Throwable $e) {
        // Log and return error
        $logger->error('SAF‑T export failed: ' . $e->getMessage());
        $response->getBody()->write(json_encode([
            'error'   => 'Export failed',
            'details' => $e->getMessage(),
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}