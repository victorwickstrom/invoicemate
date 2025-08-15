<?php
/**
 * SAF‑T export routes.
 *
 * This simplified implementation consolidates the two previous SAF‑T export
 * controllers into a single route. It reads accounts, contacts and
 * ledger entries for the specified organization and period, builds a
 * minimal SAF‑T compliant XML document and returns it as an
 * attachment. Accounts are filtered on `isHidden = 0` (the database
 * equivalent of `isActive`) and restricted to the organization. A
 * logger is used when available. Additional fields such as journals
 * grouping can be added as needed.
 */

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Routing\RouteCollectorProxy;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

return function ($app) {
    $container = $app->getContainer();
    $app->group('/v1/{organizationId}/saft', function (RouteCollectorProxy $group) use ($container) {
        $group->get('/export', function (Request $request, Response $response, array $args) use ($container) {
            // Validate DB connection
            if (!$container->has('db')) {
                $response->getBody()->write(json_encode(['error' => 'Database connection not found']));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }
            /** @var PDO $pdo */
            $pdo = $container->get('db');
            // Logger
            $logger = null;
            if ($container->has('logger')) {
                $logger = $container->get('logger');
            } else {
                $logger = new Logger('saft_export');
                $logger->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));
            }
            $orgId = $args['organizationId'];
            $queryParams = $request->getQueryParams();
            $fromDate = isset($queryParams['from']) && trim($queryParams['from']) !== '' ? $queryParams['from'] : null;
            $toDate   = isset($queryParams['to']) && trim($queryParams['to']) !== '' ? $queryParams['to'] : null;
            // Date validation
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
                // Accounts: select active accounts for organization
                $stmtAccounts = $pdo->prepare('SELECT accountNumber, name, vatCode FROM account WHERE (isHidden = 0 OR isHidden IS NULL) AND organization_id = :orgId');
                $stmtAccounts->execute([':orgId' => $orgId]);
                $accounts = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);
                // Contacts: customers and suppliers
                $stmtCustomers = $pdo->prepare('SELECT * FROM contacts WHERE organization_id = :orgId AND is_debitor = 1 AND deleted_at IS NULL');
                $stmtCustomers->execute(['orgId' => $orgId]);
                $customers = $stmtCustomers->fetchAll(PDO::FETCH_ASSOC);
                $stmtSuppliers = $pdo->prepare('SELECT * FROM contacts WHERE organization_id = :orgId AND is_creditor = 1 AND deleted_at IS NULL');
                $stmtSuppliers->execute(['orgId' => $orgId]);
                $suppliers = $stmtSuppliers->fetchAll(PDO::FETCH_ASSOC);
                // Ledger entries
                $query = 'SELECT * FROM entries WHERE organization_id = :orgId';
                $params = ['orgId' => $orgId];
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
                foreach ($params as $key => $val) {
                    $stmtEntries->bindValue(':' . $key, $val);
                }
                $stmtEntries->execute();
                $entries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);
                // Build XML document
                $xml = new SimpleXMLElement('<AuditFile></AuditFile>');
                // Header
                $header = $xml->addChild('Header');
                $header->addChild('AuditFileVersion', '1.0');
                $header->addChild('AuditFileCountry', 'DK');
                $header->addChild('AuditFileDateCreated', date('Y-m-d'));
                // Company details
                $stmtOrg = $pdo->prepare('SELECT vat_number, name, street, city, zip_code, country_key FROM organizations WHERE id = :id');
                $stmtOrg->execute([':id' => $orgId]);
                $org = $stmtOrg->fetch(PDO::FETCH_ASSOC);
                $company = $header->addChild('Company');
                // Use vat_number as CompanyID (CVR)
                $company->addChild('CompanyID', htmlspecialchars($org['vat_number'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $company->addChild('Name', htmlspecialchars($org['name'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $address = $company->addChild('CompanyAddress');
                $address->addChild('StreetName', htmlspecialchars($org['street'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $address->addChild('City', htmlspecialchars($org['city'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $address->addChild('PostalCode', htmlspecialchars($org['zip_code'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                $address->addChild('Country', htmlspecialchars($org['country_key'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                // Selection
                $selection = $header->addChild('SelectionCriteria');
                if ($fromDate) { $selection->addChild('SelectionStartDate', $fromDate); }
                if ($toDate) { $selection->addChild('SelectionEndDate', $toDate); }
                // Master files
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
                    $cust = $master->addChild('Customer');
                    $cust->addChild('CustomerID', htmlspecialchars($contact['contact_guid'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $cust->addChild('CustomerName', htmlspecialchars($contact['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $addr = $cust->addChild('BillingAddress');
                    $addr->addChild('StreetName', htmlspecialchars($contact['street'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $addr->addChild('City', htmlspecialchars($contact['city'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $addr->addChild('PostalCode', htmlspecialchars($contact['zip_code'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $addr->addChild('Country', htmlspecialchars($contact['country_key'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                }
                // Suppliers
                foreach ($suppliers as $contact) {
                    $sup = $master->addChild('Supplier');
                    $sup->addChild('SupplierID', htmlspecialchars($contact['contact_guid'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $sup->addChild('SupplierName', htmlspecialchars($contact['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $addr = $sup->addChild('BillingAddress');
                    $addr->addChild('StreetName', htmlspecialchars($contact['street'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $addr->addChild('City', htmlspecialchars($contact['city'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $addr->addChild('PostalCode', htmlspecialchars($contact['zip_code'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $addr->addChild('Country', htmlspecialchars($contact['country_key'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                }
                // VAT table
                $stmtVat = $pdo->query('SELECT vat_code, vat_rate FROM vat_type');
                $vatTypes = $stmtVat ? $stmtVat->fetchAll(PDO::FETCH_ASSOC) : [];
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
                // GeneralLedgerEntries
                $glEntries = $xml->addChild('GeneralLedgerEntries');
                // Group entries by voucher_number to form simple journals
                $currentVoucher = null;
                $journalNode = null;
                foreach ($entries as $entry) {
                    if ($currentVoucher !== $entry['voucher_number']) {
                        // New journal
                        $currentVoucher = $entry['voucher_number'];
                        $journalNode = $glEntries->addChild('Journal');
                        $journalNode->addChild('JournalID', htmlspecialchars((string) $currentVoucher, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                        $journalNode->addChild('Description', htmlspecialchars($entry['voucher_type'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    }
                    $transaction = $journalNode->addChild('Transaction');
                    $transaction->addChild('TransactionID', htmlspecialchars((string) $entry['id'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $transaction->addChild('TransactionDate', htmlspecialchars($entry['entry_date'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $line = $transaction->addChild('Line');
                    $line->addChild('AccountID', htmlspecialchars((string) $entry['account_number'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $line->addChild('DebitCreditIndicator', $entry['amount'] >= 0 ? 'Credit' : 'Debit');
                    $line->addChild('Amount', number_format(abs((float) $entry['amount']), 2, '.', ''));
                    if (!empty($entry['description'])) {
                        $line->addChild('Description', htmlspecialchars($entry['description'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    }
                    if (!empty($entry['vat_code'])) {
                        $line->addChild('TaxCode', htmlspecialchars($entry['vat_code'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    }
                }
                // Output XML
                $xmlString = $xml->asXML();
                $filename = 'SAFT_' . $orgId . '_' . date('Ymd') . '.xml';
                $response->getBody()->write($xmlString);
                return $response
                    ->withHeader('Content-Type', 'application/xml')
                    ->withHeader('Content-Disposition', 'attachment; filename=' . $filename);
            } catch (Throwable $e) {
                $logger->error('SAF‑T export failed: ' . $e->getMessage());
                $response->getBody()->write(json_encode(['error' => 'Failed to generate SAF‑T export']));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }
        });
    });
};