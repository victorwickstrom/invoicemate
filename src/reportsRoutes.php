<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

/**
 * Report routes.
 *
 * Reports in the original implementation simply read and write cached rows in the
 * `reports` table. This refactored file adds automatic generation of reports
 * from the ledger when no pre‑generated data exists. When requesting a
 * balance, result or primo report for a given accounting year the handler
 * first looks up cached rows; if none are found it aggregates entries in the
 * `entries` table for the year and returns those totals without persisting
 * them. This ensures that reports always reflect the latest booked data.
 */
return function (App $app) {
    $container = $app->getContainer();

    /**
     * Helper to build a date range for the given accounting year. If the year
     * doesn't exist a 404 is returned. Returns [fromDate, toDate].
     */
    $resolveYearRange = function (PDO $pdo, string $orgId, string $year) {
        $stmt = $pdo->prepare('SELECT from_date, to_date FROM accounting_year WHERE organization_id = :orgId AND name = :year');
        $stmt->execute([':orgId' => $orgId, ':year' => $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Some installations use numeric ID rather than name
            $stmt = $pdo->prepare('SELECT from_date, to_date FROM accounting_year WHERE organization_id = :orgId AND id = :yearId');
            $stmt->execute([':orgId' => $orgId, ':yearId' => $year]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        return $row;
    };

    /**
     * Generates a report by summing entries per account. Accepts a closure to
     * filter account numbers for different report types (balance vs result).
     */
    $generateReport = function (PDO $pdo, string $orgId, array $dateRange, callable $accountFilter) {
        [$from, $to] = $dateRange;
        // Aggregate ledger entries for the period
        $sql = "SELECT account_number, SUM(amount) AS balance FROM entries WHERE organization_id = :orgId AND entry_date BETWEEN :from AND :to GROUP BY account_number";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':orgId' => $orgId, ':from' => $from, ':to' => $to]);
        $balances = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        // Fetch account metadata for used accounts
        $accounts = [];
        if ($balances) {
            $inPlaceholders = implode(',', array_fill(0, count($balances), '?'));
            $stmtAcc = $pdo->prepare("SELECT accountNumber, name FROM account WHERE organization_id = ? AND accountNumber IN ($inPlaceholders)");
            $params = array_merge([$orgId], array_keys($balances));
            $stmtAcc->execute($params);
            $accounts = $stmtAcc->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $report = [];
        foreach ($balances as $accNumber => $amount) {
            if (!$accountFilter((int)$accNumber)) {
                continue;
            }
            $report[] = [
                'account_number' => (int) $accNumber,
                'account_name'   => $accounts[$accNumber] ?? null,
                'amount'         => (float) $amount
            ];
        }
        return $report;
    };

    // Balance report: show all accounts regardless of sign
    $app->get('/v1/{organizationId}/{accountingYear}/reports/balance', function (Request $request, Response $response, array $args) use ($container, $resolveYearRange, $generateReport) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $year = $args['accountingYear'];
        // Attempt to read cached rows
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE organization_id = ? AND accounting_year = ? AND report_type = 'balance'");
        $stmt->execute([$orgId, $year]);
        $cached = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($cached && count($cached) > 0) {
            $response->getBody()->write(json_encode($cached));
            return $response->withHeader('Content-Type', 'application/json');
        }
        // Generate from ledger
        $range = $resolveYearRange($pdo, $orgId, $year);
        if (!$range) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Accounting year not found']));
        }
        $report = $generateReport($pdo, $orgId, [$range['from_date'], $range['to_date']], function ($accNumber) {
            // For a balance sheet we include all accounts (assets, liabilities, equity)
            return true;
        });
        $response->getBody()->write(json_encode($report));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Result report: include income and expense accounts only
    $app->get('/v1/{organizationId}/{accountingYear}/reports/result', function (Request $request, Response $response, array $args) use ($container, $resolveYearRange, $generateReport) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $year = $args['accountingYear'];
        // Try cached
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE organization_id = ? AND accounting_year = ? AND report_type = 'result'");
        $stmt->execute([$orgId, $year]);
        $cached = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($cached && count($cached) > 0) {
            $response->getBody()->write(json_encode($cached));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $range = $resolveYearRange($pdo, $orgId, $year);
        if (!$range) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Accounting year not found']));
        }
        $report = $generateReport($pdo, $orgId, [$range['from_date'], $range['to_date']], function ($accNumber) {
            // In Danish accounting: income accounts typically start with 5 and 8, expenses with 6 and 7
            $first = (int) substr($accNumber, 0, 1);
            return in_array($first, [5, 6, 7, 8]);
        });
        $response->getBody()->write(json_encode($report));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Primo report (opening balances) – returns cached rows only
    $app->get('/v1/{organizationId}/{accountingYear}/reports/primo', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE organization_id = ? AND accounting_year = ? AND report_type = 'primo'");
        $stmt->execute([$args['organizationId'], $args['accountingYear']]);
        $report = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($report));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Saldo report (synonym for balance). If no cached entries exist this
    // generates a balance report using the same logic as the balance route.
    $app->get('/v1/{organizationId}/{accountingYear}/reports/saldo', function (Request $request, Response $response, array $args) use ($container, $resolveYearRange, $generateReport) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $year = $args['accountingYear'];
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE organization_id = ? AND accounting_year = ? AND report_type = 'balance'");
        $stmt->execute([$orgId, $year]);
        $cached = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($cached && count($cached) > 0) {
            $response->getBody()->write(json_encode($cached));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $range = $resolveYearRange($pdo, $orgId, $year);
        if (!$range) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Accounting year not found']));
        }
        $report = $generateReport($pdo, $orgId, [$range['from_date'], $range['to_date']], function ($accNumber) {
            return true;
        });
        $response->getBody()->write(json_encode($report));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Create a report entry (cached)
    $app->post('/v1/{organizationId}/{accountingYear}/reports', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $data = $request->getParsedBody();
        $stmt = $pdo->prepare("INSERT INTO reports (organization_id, accounting_year, report_type, account_name, account_number, amount, show_zero_account, show_account_no, include_summary_account, include_ledger_entries, show_vat_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $args['organizationId'], $args['accountingYear'], $data['report_type'], $data['account_name'], $data['account_number'], $data['amount'], $data['show_zero_account'], $data['show_account_no'], $data['include_summary_account'], $data['include_ledger_entries'], $data['show_vat_type']
        ]);
        $response->getBody()->write(json_encode(['message' => 'Report entry added successfully']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Update a report entry
    $app->put('/v1/{organizationId}/{accountingYear}/reports/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $data = $request->getParsedBody();
        $stmt = $pdo->prepare("UPDATE reports SET report_type = ?, account_name = ?, account_number = ?, amount = ?, show_zero_account = ?, show_account_no = ?, include_summary_account = ?, include_ledger_entries = ?, show_vat_type = ? WHERE id = ?");
        $stmt->execute([
            $data['report_type'], $data['account_name'], $data['account_number'], $data['amount'], $data['show_zero_account'], $data['show_account_no'], $data['include_summary_account'], $data['include_ledger_entries'], $data['show_vat_type'], $args['id']
        ]);
        $response->getBody()->write(json_encode(['message' => 'Report entry updated successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Delete a report entry
    $app->delete('/v1/{organizationId}/{accountingYear}/reports/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $stmt = $pdo->prepare("DELETE FROM reports WHERE id = ?");
        $stmt->execute([$args['id']]);
        $response->getBody()->write(json_encode(['message' => 'Report entry deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });
};