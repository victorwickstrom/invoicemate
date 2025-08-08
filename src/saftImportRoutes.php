<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * SAF‑T import routes.
 *
 * This file defines a new route group under `/v1/{organizationId}/saft` that
 * exposes a POST endpoint `/import` for uploading and importing a SAF‑T XML
 * file. The implementation validates the uploaded file, parses the XML to
 * extract accounts, ledger entries and contacts, and persists them into
 * SQLite tables using a transaction. On success the response returns the
 * number of imported entities and any non‑fatal warnings.
 */
return function (App $app) {
    $container = $app->getContainer();

    // Group routes under /v1/{organizationId}/saft
    $app->group('/v1/{organizationId}/saft', function (RouteCollectorProxy $group) use ($container) {
        // POST /v1/{organizationId}/saft/import
        $group->post('/import', function (Request $request, Response $response, array $args) use ($container) {
            return importSaft($request, $response, $args, $container);
        });
    });
};

/**
 * Handle a SAF‑T import request.
 *
 * This function is executed when a client uploads a SAF‑T XML file. It will
 * perform basic validation on the uploaded file, parse the XML using
 * SimpleXML, map the data to internal tables (accounts, contacts and ledger
 * entries) and persist the data within a transaction. Any exceptions will
 * result in a rollback and a JSON error response. Warnings are returned
 * alongside the success status to highlight non‑critical issues encountered
 * during processing.
 *
 * @param Request           $request  PSR‑7 request
 * @param Response          $response PSR‑7 response
 * @param array             $args     Route arguments (contains organizationId)
 * @param ContainerInterface $container Dependency injection container
 *
 * @return Response Modified response containing JSON result or error
 */
function importSaft(Request $request, Response $response, array $args, ContainerInterface $container): Response
{
    // Ensure database connection exists
    if (!$container->has('db')) {
        $response->getBody()->write(json_encode(['error' => 'Database connection not found']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    $pdo = $container->get('db');

    // Resolve a logger: use the configured logger if available, otherwise log to stderr
    if ($container->has('logger')) {
        $logger = $container->get('logger');
    } else {
        $logger = new Logger('saft_import');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));
    }

    $uploadedFiles = $request->getUploadedFiles();
    if (!isset($uploadedFiles['file'])) {
        $msg = 'No file uploaded. The import endpoint expects a multipart/form-data upload with field name "file".';
        $logger->warning($msg);
        $response->getBody()->write(json_encode(['error' => $msg]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $file = $uploadedFiles['file'];
    if ($file->getError() !== UPLOAD_ERR_OK) {
        $msg = 'File upload error';
        $logger->warning($msg);
        $response->getBody()->write(json_encode(['error' => $msg]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // Read file contents
    $stream = $file->getStream();
    $content = $stream->getContents();
    $stream->close();

    // Validate XML structure
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) {
        $msg = 'Invalid XML file. Unable to parse the provided SAF‑T document.';
        $logger->warning($msg);
        $response->getBody()->write(json_encode(['error' => $msg]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $organizationId = $args['organizationId'];

    // Initialise counters and warnings
    $importedAccounts = 0;
    $importedEntries  = 0;
    $importedContacts = 0;
    $warnings         = [];

    try {
        // Begin transaction to ensure atomicity
        $pdo->beginTransaction();

        /**
         * Import GeneralLedgerAccounts -> account table
         * SAF‑T defines AccountID/AccountNumber and AccountDescription. We
         * insert each account if it does not already exist. A simple VAT code
         * heuristic is applied: accounts starting with 3xxx are sales (U25),
         * otherwise purchases (I25).
         */
        if (isset($xml->MasterFiles->GeneralLedgerAccounts->Account)) {
            foreach ($xml->MasterFiles->GeneralLedgerAccounts->Account as $account) {
                // Resolve account number
                $accNumber = null;
                if (isset($account->AccountID) && (string)$account->AccountID !== '') {
                    $accNumber = (int) $account->AccountID;
                } elseif (isset($account->AccountNumber) && (string)$account->AccountNumber !== '') {
                    $accNumber = (int) $account->AccountNumber;
                }
                if (!$accNumber) {
                    $warnings[] = 'Skipped account with missing AccountID/AccountNumber.';
                    continue;
                }
                // Resolve account name
                $accName = '';
                if (isset($account->AccountDescription) && trim((string)$account->AccountDescription) !== '') {
                    $accName = (string) $account->AccountDescription;
                } elseif (isset($account->Description) && trim((string)$account->Description) !== '') {
                    $accName = (string) $account->Description;
                } elseif (isset($account->AccountName) && trim((string)$account->AccountName) !== '') {
                    $accName = (string) $account->AccountName;
                } else {
                    $accName = 'Account ' . $accNumber;
                }
                // Determine a VAT code: default to U25 for sales (3000–3999), otherwise I25
                $vatCode = '';
                if (isset($account->TaxCode) && trim((string)$account->TaxCode) !== '') {
                    $vatCode = (string) $account->TaxCode;
                } elseif (isset($account->TaxType) && trim((string)$account->TaxType) !== '') {
                    $vatCode = (string) $account->TaxType;
                } else {
                    $vatCode = ($accNumber >= 3000 && $accNumber < 4000) ? 'U25' : 'I25';
                }
                // Insert account only if not already present
                $stmtCheck = $pdo->prepare('SELECT accountNumber FROM account WHERE accountNumber = :accountNumber');
                $stmtCheck->execute(['accountNumber' => $accNumber]);
                if (!$stmtCheck->fetch()) {
                    $stmtInsert = $pdo->prepare('INSERT INTO account (accountNumber, name, vatCode) VALUES (:accountNumber, :name, :vatCode)');
                    $stmtInsert->execute([
                        'accountNumber' => $accNumber,
                        'name'         => $accName,
                        'vatCode'      => $vatCode
                    ]);
                    $importedAccounts++;
                }
            }
        }

        /**
         * Import contacts: Customers and Suppliers into contacts table. Only
         * mandatory fields are filled; country_key defaults to DK if missing.
         */
        if (isset($xml->MasterFiles->Customer)) {
            foreach ($xml->MasterFiles->Customer as $customer) {
                $name = null;
                if (isset($customer->CustomerName) && trim((string)$customer->CustomerName) !== '') {
                    $name = (string) $customer->CustomerName;
                } elseif (isset($customer->Name) && trim((string)$customer->Name) !== '') {
                    $name = (string) $customer->Name;
                }
                if (!$name) {
                    $warnings[] = 'Skipped customer with missing name.';
                    continue;
                }
                $contactGuid = Uuid::uuid4()->toString();
                $countryKey  = 'DK';
                if (isset($customer->Country) && trim((string)$customer->Country) !== '') {
                    $countryKey = (string) $customer->Country;
                } elseif (isset($customer->CountryCode) && trim((string)$customer->CountryCode) !== '') {
                    $countryKey = (string) $customer->CountryCode;
                }
                $stmt = $pdo->prepare('INSERT INTO contacts (organization_id, contact_guid, name, country_key, is_person, is_member, use_cvr, is_debitor, is_creditor) VALUES (:orgId, :guid, :name, :countryKey, 0, 0, 0, 1, 0)');
                $stmt->execute([
                    'orgId'      => $organizationId,
                    'guid'       => $contactGuid,
                    'name'       => $name,
                    'countryKey' => $countryKey
                ]);
                $importedContacts++;
            }
        }
        if (isset($xml->MasterFiles->Supplier)) {
            foreach ($xml->MasterFiles->Supplier as $supplier) {
                $name = null;
                if (isset($supplier->SupplierName) && trim((string)$supplier->SupplierName) !== '') {
                    $name = (string) $supplier->SupplierName;
                } elseif (isset($supplier->Name) && trim((string)$supplier->Name) !== '') {
                    $name = (string) $supplier->Name;
                }
                if (!$name) {
                    $warnings[] = 'Skipped supplier with missing name.';
                    continue;
                }
                $contactGuid = Uuid::uuid4()->toString();
                $countryKey  = 'DK';
                if (isset($supplier->Country) && trim((string)$supplier->Country) !== '') {
                    $countryKey = (string) $supplier->Country;
                } elseif (isset($supplier->CountryCode) && trim((string)$supplier->CountryCode) !== '') {
                    $countryKey = (string) $supplier->CountryCode;
                }
                $stmt = $pdo->prepare('INSERT INTO contacts (organization_id, contact_guid, name, country_key, is_person, is_member, use_cvr, is_debitor, is_creditor) VALUES (:orgId, :guid, :name, :countryKey, 0, 0, 0, 0, 1)');
                $stmt->execute([
                    'orgId'      => $organizationId,
                    'guid'       => $contactGuid,
                    'name'       => $name,
                    'countryKey' => $countryKey
                ]);
                $importedContacts++;
            }
        }

        /**
         * Import ledger entries from GeneralLedgerEntries -> Journal -> Transaction -> Line.
         * Each line becomes an entry. We derive the amount by subtracting credit
         * from debit; debit minus credit yields positive values for debits and
         * negative for credits.
         */
        if (isset($xml->GeneralLedgerEntries->Journal)) {
            foreach ($xml->GeneralLedgerEntries->Journal as $journal) {
                $journalId = null;
                if (isset($journal->JournalID) && trim((string)$journal->JournalID) !== '') {
                    $journalId = (string) $journal->JournalID;
                }
                foreach ($journal->Transaction as $transaction) {
                    $voucherNumber = null;
                    if (isset($transaction->TransactionID) && trim((string)$transaction->TransactionID) !== '') {
                        $voucherNumber = (string) $transaction->TransactionID;
                    } elseif ($journalId !== null) {
                        $voucherNumber = $journalId;
                    }
                    $entryDate = null;
                    if (isset($transaction->TransactionDate) && trim((string)$transaction->TransactionDate) !== '') {
                        $entryDate = (string) $transaction->TransactionDate;
                    } elseif (isset($transaction->Date) && trim((string)$transaction->Date) !== '') {
                        $entryDate = (string) $transaction->Date;
                    }
                    foreach ($transaction->Line as $line) {
                        $accNumber = null;
                        if (isset($line->AccountID) && trim((string)$line->AccountID) !== '') {
                            $accNumber = (int) $line->AccountID;
                        } elseif (isset($line->AccountCode) && trim((string)$line->AccountCode) !== '') {
                            $accNumber = (int) $line->AccountCode;
                        }
                        if (!$accNumber) {
                            $warnings[] = 'Skipped entry with missing AccountID/AccountCode.';
                            continue;
                        }
                        $description = '';
                        if (isset($line->Description) && trim((string)$line->Description) !== '') {
                            $description = (string) $line->Description;
                        } elseif (isset($transaction->Description) && trim((string)$transaction->Description) !== '') {
                            $description = (string) $transaction->Description;
                        }
                        $debit  = 0.0;
                        $credit = 0.0;
                        if (isset($line->DebitAmount) && trim((string)$line->DebitAmount) !== '') {
                            $debit = (float) $line->DebitAmount;
                        }
                        if (isset($line->CreditAmount) && trim((string)$line->CreditAmount) !== '') {
                            $credit = (float) $line->CreditAmount;
                        }
                        $amount = $debit - $credit;
                        $entryGuid = Uuid::uuid4()->toString();
                        $stmt = $pdo->prepare('INSERT INTO entries (organization_id, account_number, account_name, entry_date, voucher_number, voucher_type, description, vat_type, vat_code, amount, entry_guid, contact_guid, entry_type) VALUES (:orgId, :accountNumber, :accountName, :entryDate, :voucherNumber, :voucherType, :description, :vatType, :vatCode, :amount, :entryGuid, :contactGuid, :entryType)');
                        $stmt->execute([
                            'orgId'        => $organizationId,
                            'accountNumber' => $accNumber,
                            'accountName'  => null,
                            'entryDate'    => $entryDate,
                            'voucherNumber' => $voucherNumber,
                            // Use ASCII hyphen in voucherType for compatibility
                            'voucherType'   => 'SAF-T',
                            'description'   => $description,
                            'vatType'       => null,
                            'vatCode'       => null,
                            'amount'        => $amount,
                            'entryGuid'     => $entryGuid,
                            'contactGuid'   => null,
                            'entryType'     => 'Normal'
                        ]);
                        $importedEntries++;
                    }
                }
            }
        }

        // Commit transaction after successful import
        $pdo->commit();
    } catch (\Throwable $e) {
        // Roll back on error
        $pdo->rollBack();
        $logger->error('SAF‑T import failed: ' . $e->getMessage());
        $response->getBody()->write(json_encode([
            'error'   => 'Import failed',
            'details' => $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    // Prepare success response
    $result = [
        'status'            => 'success',
        'imported_entries'  => $importedEntries,
        'imported_accounts' => $importedAccounts,
        'imported_contacts' => $importedContacts,
        'warnings'          => $warnings
    ];
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
}