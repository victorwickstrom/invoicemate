<?php
/**
 * SAF‑T Import Routes
 *
 * This route allows clients to upload a SAF‑T XML file and import the
 * contained master data (accounts, contacts) and ledger entries into the
 * internal database. The importer is multi‑tenant aware: it scopes all
 * inserts to the current organisation and checks for duplicate accounts and
 * contacts within that organisation before inserting new records. Ledger
 * entries are also scoped to the organisation and derive sensible defaults
 * for entry type and other metadata.
 *
 * Improvements over the original implementation include:
 *  - The database connection is resolved via the DI container using
 *    PDO::class where available, providing stronger type hints and
 *    compatibility with service aliases.
 *  - When importing accounts, the importer now checks for existing
 *    records scoped by organisation_id and includes organisation_id on
 *    inserts. This prevents mixing chart of accounts between tenants and
 *    supports a unique composite index on (organization_id, accountNumber).
 *  - Contacts are deduplicated by name and organisation; if a contact
 *    already exists with the same name the importer skips insertion and
 *    records a warning. Both customers and suppliers are imported with
 *    sensible defaults for country and flags.
 *  - Ledger entries honour the transaction type where present: SAF‑T
 *    distinguishes normal transactions from opening balances. Opening
 *    balances are mapped to entry_type "Primo"; all others default to
 *    "Normal". Voucher type is set to "SAF‑T" and ASCII hyphens are used
 *    for compatibility. Amounts are calculated as debit minus credit to
 *    preserve sign conventions.
 *  - Comprehensive error handling rolls back the transaction on any
 *    exception and returns structured JSON containing details and warnings.
 */

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;
use Slim\Routing\RouteCollectorProxy;

return function ($app) {
    $container = $app->getContainer();
    /**
     * Group the SAF‑T routes under /v1/{organizationId}/saft to ensure
     * organisation context is always provided in the path. Only the import
     * endpoint is exposed for now.
     */
    $app->group('/v1/{organizationId}/saft', function (RouteCollectorProxy $group) use ($container) {
        $group->post('/import', function (Request $request, Response $response, array $args) use ($container) {
            return importSaft($request, $response, $args, $container);
        });
    });
};

/**
 * Import a SAF‑T document for a given organisation.
 *
 * @param Request             $request  The PSR‑7 request containing the uploaded file
 * @param Response            $response The PSR‑7 response for returning JSON
 * @param array               $args     Route arguments (includes organisationId)
 * @param ContainerInterface  $container The application container
 *
 * @return Response Modified response with JSON result or error
 */
function importSaft(Request $request, Response $response, array $args, ContainerInterface $container): Response
{
    // Resolve PDO instance from container. Prefer the class alias if defined
    if ($container->has(PDO::class)) {
        $pdo = $container->get(PDO::class);
    } elseif ($container->has('db')) {
        $pdo = $container->get('db');
    } else {
        $response->getBody()->write(json_encode(['error' => 'Database connection not configured']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    // Resolve a logger. Use existing logger or fallback to stderr
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

    // Read the uploaded XML content into a string
    $stream  = $file->getStream();
    $content = $stream->getContents();
    $stream->close();

    // Parse the XML using SimpleXML with error suppression to capture invalid files
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) {
        $msg = 'Invalid XML file. Unable to parse the provided SAF‑T document.';
        $logger->warning($msg);
        $response->getBody()->write(json_encode(['error' => $msg]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $organizationId = $args['organizationId'];
    $importedAccounts = 0;
    $importedEntries  = 0;
    $importedContacts = 0;
    $warnings         = [];

    try {
        // Use a transaction to ensure all inserts succeed atomically
        $pdo->beginTransaction();

        // Cache prepared statements for account and contact lookups to avoid
        // repeatedly parsing SQL during loops
        $stmtAccountCheck = $pdo->prepare('SELECT accountNumber FROM account WHERE organization_id = :orgId AND accountNumber = :accountNumber');
        $stmtAccountInsert = $pdo->prepare('INSERT INTO account (organization_id, accountNumber, name, vatCode) VALUES (:orgId, :accountNumber, :name, :vatCode)');
        $stmtContactCheck = $pdo->prepare('SELECT contact_guid FROM contacts WHERE organization_id = :orgId AND name = :name');
        $stmtContactInsert = $pdo->prepare('INSERT INTO contacts (organization_id, contact_guid, name, country_key, is_person, is_member, use_cvr, is_debitor, is_creditor) VALUES (:orgId, :guid, :name, :countryKey, :isPerson, :isMember, :useCvr, :isDebitor, :isCreditor)');
        $stmtEntryInsert = $pdo->prepare('INSERT INTO entries (organization_id, account_number, account_name, entry_date, voucher_number, voucher_type, description, vat_type, vat_code, amount, entry_guid, contact_guid, entry_type) VALUES (:orgId, :accountNumber, :accountName, :entryDate, :voucherNumber, :voucherType, :description, :vatType, :vatCode, :amount, :entryGuid, :contactGuid, :entryType)');

        /**
         * Import GeneralLedgerAccounts: accounts are scoped by organisation. We
         * honour TaxCode or TaxType where provided; otherwise apply a simple
         * heuristic: accounts numbered 3000‑3999 are sales (U25) while all
         * others default to purchases (I25). If the account already exists for
         * the organisation we skip insertion and log a warning.
         */
        if (isset($xml->MasterFiles->GeneralLedgerAccounts->Account)) {
            foreach ($xml->MasterFiles->GeneralLedgerAccounts->Account as $account) {
                // Determine account number (AccountID/AccountNumber). Cast to int
                $accNumber = null;
                if (isset($account->AccountID) && trim((string) $account->AccountID) !== '') {
                    $accNumber = (int) $account->AccountID;
                } elseif (isset($account->AccountNumber) && trim((string) $account->AccountNumber) !== '') {
                    $accNumber = (int) $account->AccountNumber;
                }
                if (!$accNumber) {
                    $warnings[] = 'Skipped account with missing AccountID/AccountNumber.';
                    continue;
                }
                // Determine account name. Use AccountDescription or Description/AccountName as fallback
                $accName = '';
                if (isset($account->AccountDescription) && trim((string) $account->AccountDescription) !== '') {
                    $accName = (string) $account->AccountDescription;
                } elseif (isset($account->Description) && trim((string) $account->Description) !== '') {
                    $accName = (string) $account->Description;
                } elseif (isset($account->AccountName) && trim((string) $account->AccountName) !== '') {
                    $accName = (string) $account->AccountName;
                } else {
                    $accName = 'Account ' . $accNumber;
                }
                // Derive VAT code from TaxCode/TaxType or simple number range heuristic
                $vatCode = '';
                if (isset($account->TaxCode) && trim((string) $account->TaxCode) !== '') {
                    $vatCode = (string) $account->TaxCode;
                } elseif (isset($account->TaxType) && trim((string) $account->TaxType) !== '') {
                    $vatCode = (string) $account->TaxType;
                } else {
                    $vatCode = ($accNumber >= 3000 && $accNumber < 4000) ? 'U25' : 'I25';
                }
                // Check if account exists for this organisation
                $stmtAccountCheck->execute([
                    'orgId'         => $organizationId,
                    'accountNumber' => $accNumber
                ]);
                if ($stmtAccountCheck->fetch()) {
                    $warnings[] = 'Account ' . $accNumber . ' already exists for organisation; skipped.';
                    continue;
                }
                // Insert new account
                $stmtAccountInsert->execute([
                    'orgId'         => $organizationId,
                    'accountNumber' => $accNumber,
                    'name'         => $accName,
                    'vatCode'      => $vatCode
                ]);
                $importedAccounts++;
            }
        }

        /**
         * Import Contacts (Customers and Suppliers). We only insert a contact if
         * another contact with the same name does not already exist for the
         * organisation. The importer generates a UUID for the contact GUID and
         * defaults all boolean flags to 0 except is_debitor/is_creditor.
         */
        // Customers
        if (isset($xml->MasterFiles->Customer)) {
            foreach ($xml->MasterFiles->Customer as $customer) {
                $name = null;
                if (isset($customer->CustomerName) && trim((string) $customer->CustomerName) !== '') {
                    $name = (string) $customer->CustomerName;
                } elseif (isset($customer->Name) && trim((string) $customer->Name) !== '') {
                    $name = (string) $customer->Name;
                }
                if (!$name) {
                    $warnings[] = 'Skipped customer with missing name.';
                    continue;
                }
                // Check for existing contact with same name
                $stmtContactCheck->execute([
                    'orgId' => $organizationId,
                    'name'  => $name
                ]);
                if ($stmtContactCheck->fetch()) {
                    $warnings[] = 'Customer "' . $name . '" already exists for organisation; skipped.';
                    continue;
                }
                $contactGuid = Uuid::uuid4()->toString();
                // Derive country code; default to DK
                $countryKey = 'DK';
                if (isset($customer->Country) && trim((string) $customer->Country) !== '') {
                    $countryKey = (string) $customer->Country;
                } elseif (isset($customer->CountryCode) && trim((string) $customer->CountryCode) !== '') {
                    $countryKey = (string) $customer->CountryCode;
                }
                // Insert contact with debitor flag
                $stmtContactInsert->execute([
                    'orgId'      => $organizationId,
                    'guid'       => $contactGuid,
                    'name'       => $name,
                    'countryKey' => $countryKey,
                    'isPerson'   => 0,
                    'isMember'   => 0,
                    'useCvr'     => 0,
                    'isDebitor'  => 1,
                    'isCreditor' => 0
                ]);
                $importedContacts++;
            }
        }
        // Suppliers
        if (isset($xml->MasterFiles->Supplier)) {
            foreach ($xml->MasterFiles->Supplier as $supplier) {
                $name = null;
                if (isset($supplier->SupplierName) && trim((string) $supplier->SupplierName) !== '') {
                    $name = (string) $supplier->SupplierName;
                } elseif (isset($supplier->Name) && trim((string) $supplier->Name) !== '') {
                    $name = (string) $supplier->Name;
                }
                if (!$name) {
                    $warnings[] = 'Skipped supplier with missing name.';
                    continue;
                }
                // Check for existing contact with same name
                $stmtContactCheck->execute([
                    'orgId' => $organizationId,
                    'name'  => $name
                ]);
                if ($stmtContactCheck->fetch()) {
                    $warnings[] = 'Supplier "' . $name . '" already exists for organisation; skipped.';
                    continue;
                }
                $contactGuid = Uuid::uuid4()->toString();
                $countryKey = 'DK';
                if (isset($supplier->Country) && trim((string) $supplier->Country) !== '') {
                    $countryKey = (string) $supplier->Country;
                } elseif (isset($supplier->CountryCode) && trim((string) $supplier->CountryCode) !== '') {
                    $countryKey = (string) $supplier->CountryCode;
                }
                $stmtContactInsert->execute([
                    'orgId'      => $organizationId,
                    'guid'       => $contactGuid,
                    'name'       => $name,
                    'countryKey' => $countryKey,
                    'isPerson'   => 0,
                    'isMember'   => 0,
                    'useCvr'     => 0,
                    'isDebitor'  => 0,
                    'isCreditor' => 1
                ]);
                $importedContacts++;
            }
        }

        /**
         * Import ledger entries from GeneralLedgerEntries. Each journal
         * transaction may contain multiple lines. For each line we derive the
         * account number and compute the signed amount (debit minus credit).
         * Entry type is inferred from the Transaction Type: if present and
         * matching "OpeningBalance" then the entry type is "Primo"; otherwise
         * it defaults to "Normal". Contact GUID and VAT metadata are
         * currently set to null because SAF‑T generally does not reference
         * specific contacts on ledger lines.
         */
        if (isset($xml->GeneralLedgerEntries->Journal)) {
            foreach ($xml->GeneralLedgerEntries->Journal as $journal) {
                $journalId = null;
                if (isset($journal->JournalID) && trim((string) $journal->JournalID) !== '') {
                    $journalId = (string) $journal->JournalID;
                }
                foreach ($journal->Transaction as $transaction) {
                    // Determine voucher number from TransactionID or fallback to JournalID
                    $voucherNumber = null;
                    if (isset($transaction->TransactionID) && trim((string) $transaction->TransactionID) !== '') {
                        $voucherNumber = (string) $transaction->TransactionID;
                    } elseif ($journalId !== null) {
                        $voucherNumber = $journalId;
                    }
                    // Determine entry date from TransactionDate/Date
                    $entryDate = null;
                    if (isset($transaction->TransactionDate) && trim((string) $transaction->TransactionDate) !== '') {
                        $entryDate = (string) $transaction->TransactionDate;
                    } elseif (isset($transaction->Date) && trim((string) $transaction->Date) !== '') {
                        $entryDate = (string) $transaction->Date;
                    }
                    // Determine entry type: OpeningBalance -> Primo, else Normal
                    $entryType = 'Normal';
                    if (isset($transaction->Type) && trim((string) $transaction->Type) !== '') {
                        $type = strtolower((string) $transaction->Type);
                        if (strpos($type, 'opening') !== false) {
                            $entryType = 'Primo';
                        }
                    }
                    // Loop through lines of the transaction
                    foreach ($transaction->Line as $line) {
                        // Determine account number from AccountID/AccountCode; cast to int
                        $accNumber = null;
                        if (isset($line->AccountID) && trim((string) $line->AccountID) !== '') {
                            $accNumber = (int) $line->AccountID;
                        } elseif (isset($line->AccountCode) && trim((string) $line->AccountCode) !== '') {
                            $accNumber = (int) $line->AccountCode;
                        }
                        if (!$accNumber) {
                            $warnings[] = 'Skipped entry with missing AccountID/AccountCode.';
                            continue;
                        }
                        // Fetch optional description from line or transaction
                        $description = '';
                        if (isset($line->Description) && trim((string) $line->Description) !== '') {
                            $description = (string) $line->Description;
                        } elseif (isset($transaction->Description) && trim((string) $transaction->Description) !== '') {
                            $description = (string) $transaction->Description;
                        }
                        // Compute debit and credit. Null values default to zero
                        $debit  = 0.0;
                        $credit = 0.0;
                        if (isset($line->DebitAmount) && trim((string) $line->DebitAmount) !== '') {
                            $debit = (float) $line->DebitAmount;
                        }
                        if (isset($line->CreditAmount) && trim((string) $line->CreditAmount) !== '') {
                            $credit = (float) $line->CreditAmount;
                        }
                        $amount = $debit - $credit;
                        $entryGuid = Uuid::uuid4()->toString();
                        // Insert entry. Account name and VAT data could be looked up
                        // from account table if desired; they remain null here.
                        $stmtEntryInsert->execute([
                            'orgId'        => $organizationId,
                            'accountNumber' => $accNumber,
                            'accountName'  => null,
                            'entryDate'    => $entryDate,
                            'voucherNumber' => $voucherNumber,
                            'voucherType'   => 'SAF-T',
                            'description'   => $description,
                            'vatType'       => null,
                            'vatCode'       => null,
                            'amount'        => $amount,
                            'entryGuid'     => $entryGuid,
                            'contactGuid'   => null,
                            'entryType'     => $entryType
                        ]);
                        $importedEntries++;
                    }
                }
            }
        }

        // Commit after all inserts succeed
        $pdo->commit();
    } catch (\Throwable $e) {
        // Roll back on any failure and return error details
        $pdo->rollBack();
        $logger->error('SAF‑T import failed: ' . $e->getMessage());
        $response->getBody()->write(json_encode([
            'error'   => 'Import failed',
            'details' => $e->getMessage(),
            'warnings' => $warnings
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    // Build success response with counts and any non‑fatal warnings
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