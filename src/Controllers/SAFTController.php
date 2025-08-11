<?php
namespace Invoicemate\Controllers;

use PDO;
use DOMDocument;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class SAFTController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Export accounting data as SAF-T XML.
     * Endpoint: GET /v1/{org}/saft/export?from=...&to=... or ?year=YYYY
     */
    public function export(Request $request, Response $response, array $args): Response
    {
        $orgId = (int)$args['organizationId'];
        $params = $request->getQueryParams();
        $from = $params['from'] ?? null;
        $to = $params['to'] ?? null;
        $year = $params['year'] ?? null;
        if (!$year && (!$from || !$to)) {
            return $this->json($response, ['error' => 'Specify either year or from/to dates'], 400);
        }
        if ($year) {
            $from = $year . '-01-01';
            $to = $year . '-12-31';
        }
        // Build simple XML document. In a real implementation, data would
        // be queried from the accounting tables and mapped to SAF-T fields.
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $auditFile = $doc->createElement('AuditFile');
        $doc->appendChild($auditFile);

        // Header
        $header = $doc->createElement('Header');
        $header->appendChild($this->createElem($doc, 'AuditFileVersion', '1.0'));
        $header->appendChild($this->createElem($doc, 'CompanyID', (string)$orgId));
        $header->appendChild($this->createElem($doc, 'StartDate', $from));
        $header->appendChild($this->createElem($doc, 'EndDate', $to));
        $auditFile->appendChild($header);

        // MasterFiles (stub)
        $masterFiles = $doc->createElement('MasterFiles');
        // Accounts
        $chartOfAccounts = $doc->createElement('ChartOfAccounts');
        // Query accounts table if exists
        try {
            $accounts = $this->pdo->query('SELECT id, code, name FROM account')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $accounts = [];
        }
        foreach ($accounts as $acc) {
            $account = $doc->createElement('Account');
            $account->appendChild($this->createElem($doc, 'AccountID', $acc['code']));
            $account->appendChild($this->createElem($doc, 'AccountDescription', $acc['name']));
            $chartOfAccounts->appendChild($account);
        }
        $masterFiles->appendChild($chartOfAccounts);
        $auditFile->appendChild($masterFiles);

        // GeneralLedgerEntries (stub)
        $entries = $doc->createElement('GeneralLedgerEntries');
        // Query ledger table if exists
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM ledger WHERE organization_id = :org AND date BETWEEN :from AND :to');
            $stmt->execute([':org' => $orgId, ':from' => $from, ':to' => $to]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $row) {
            $entry = $doc->createElement('JournalEntry');
            $entry->appendChild($this->createElem($doc, 'JournalID', (string)$row['id']));
            $entry->appendChild($this->createElem($doc, 'TransactionDate', $row['date']));
            $entry->appendChild($this->createElem($doc, 'Description', $row['description'] ?? ''));
            // Lines
            $lines = $doc->createElement('Lines');
            $line = $doc->createElement('Line');
            $line->appendChild($this->createElem($doc, 'AccountID', $row['account_id'] ?? ''));
            $line->appendChild($this->createElem($doc, 'Amount', (string)$row['amount']));
            $lines->appendChild($line);
            $entry->appendChild($lines);
            $entries->appendChild($entry);
        }
        $auditFile->appendChild($entries);

        $xml = $doc->saveXML();
        // Validate if XSD exists
        $schemaPath = __DIR__ . '/../../schema/SAFT_DK.xsd';
        if (is_file($schemaPath)) {
            $validDoc = new DOMDocument('1.0', 'UTF-8');
            $validDoc->loadXML($xml);
            if (!$validDoc->schemaValidate($schemaPath)) {
                return $this->json($response, ['error' => 'SAF-T XML failed schema validation'], 422);
            }
        }
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml');
    }

    private function createElem(DOMDocument $doc, string $name, string $value)
    {
        $el = $doc->createElement($name);
        $el->appendChild($doc->createTextNode($value));
        return $el;
    }

    private function json(Response $response, $data, int $status = 200): Response
    {
        $response->getBody()->rewind();
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}