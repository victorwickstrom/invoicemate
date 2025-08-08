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
        // Company details placeholder (could be extended with real data)
        $company = $header->addChild('Company');
        $company->addChild('RegistrationNumber', '');
        $company->addChild('Name', '');
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
            // Use AccountDescription as described in Danish SAF‑T standard
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

        // Build ledger entries
        $glEntries = $xml->addChild('GeneralLedgerEntries');
        // We'll create a single journal for all transactions
        $journal = $glEntries->addChild('Journal');
        $journal->addChild('JournalID', '1');
        foreach ($entries as $entry) {
            $transaction = $journal->addChild('Transaction');
            // Use voucher number or entry GUID as TransactionID
            $transId = isset($entry['voucher_number']) && $entry['voucher_number'] !== null ? $entry['voucher_number'] : $entry['entry_guid'];
            $transaction->addChild('TransactionID', htmlspecialchars((string) $transId, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            if (!empty($entry['entry_date'])) {
                $transaction->addChild('TransactionDate', htmlspecialchars($entry['entry_date'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            }
            if (!empty($entry['description'])) {
                $transaction->addChild('Description', htmlspecialchars($entry['description'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            }
            // Add single line per entry
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