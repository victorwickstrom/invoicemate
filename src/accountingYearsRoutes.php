<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

/**
 * Accounting year routes.
 *
 * This file provides CRUD operations for accounting years plus the ability to lock
 * and unlock a year. Each accounting year belongs to an organization and is
 * defined by a from and to date. When a year is locked no further bookings
 * may be performed within its date range. Locking and unlocking is idempotent
 * and will ensure that the underlying `is_locked` column exists. When creating
 * a new accounting year the handler validates that the period follows the
 * previous year without gaps or overlaps. On creation the new year is
 * automatically unlocked.
 */
return function (App $app) {
    $container = $app->getContainer();

    // Fetch all accounting years for an organization
    $app->get('/{organizationId}/accountingyears', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];

        $stmt = $pdo->prepare("SELECT * FROM accounting_year WHERE organization_id = :organizationId ORDER BY from_date DESC");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->execute();
        $accountingYears = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($accountingYears));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Fetch a specific accounting year
    $app->get('/{organizationId}/accountingyears/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $id = $args['id'];

        $stmt = $pdo->prepare("SELECT * FROM accounting_year WHERE organization_id = :organizationId AND id = :id");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $accountingYear = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$accountingYear) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(["error" => "Accounting year not found"]));
        }

        $response->getBody()->write(json_encode($accountingYear));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Create a new accounting year
    $app->post('/{organizationId}/accountingyears', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        // Validate input
        if (!isset($data['name'], $data['from_date'], $data['to_date'], $data['salary_sum_tax_state_enum'])) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(["error" => "Missing required fields"]));
        }

        // Ensure there is no gap or overlap with the previous accounting year
        $stmtPrev = $pdo->prepare("SELECT to_date FROM accounting_year WHERE organization_id = :orgId ORDER BY to_date DESC LIMIT 1");
        $stmtPrev->execute([':orgId' => $organizationId]);
        $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);
        if ($prev) {
            $expectedFrom = date('Y-m-d', strtotime($prev['to_date'] . ' +1 day'));
            if ($data['from_date'] !== $expectedFrom) {
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode([
                        "error" => "New accounting year must start the day after previous ends",
                        "expected_from_date" => $expectedFrom
                    ]));
            }
        }

        // Insert the new year
        $stmt = $pdo->prepare(
            "INSERT INTO accounting_year (organization_id, name, from_date, to_date, salary_sum_tax_state_enum, is_locked) " .
            "VALUES (:organizationId, :name, :from_date, :to_date, :salary_sum_tax_state_enum, 0)"
        );
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindParam(':from_date', $data['from_date'], PDO::PARAM_STR);
        $stmt->bindParam(':to_date', $data['to_date'], PDO::PARAM_STR);
        $stmt->bindParam(':salary_sum_tax_state_enum', $data['salary_sum_tax_state_enum'], PDO::PARAM_STR);
        $stmt->execute();

        return $response->withStatus(201)->withHeader('Content-Type', 'application/json')
            ->write(json_encode(["message" => "Accounting year created successfully"]));
    });

    // Update an accounting year
    $app->put('/{organizationId}/accountingyears/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $id = $args['id'];
        $data = $request->getParsedBody();

        if (!isset($data['name'], $data['from_date'], $data['to_date'], $data['salary_sum_tax_state_enum'])) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(["error" => "Missing required fields"]));
        }

        $stmt = $pdo->prepare("UPDATE accounting_year SET name = :name, from_date = :from_date, to_date = :to_date, salary_sum_tax_state_enum = :salary_sum_tax_state_enum WHERE organization_id = :organizationId AND id = :id");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindParam(':from_date', $data['from_date'], PDO::PARAM_STR);
        $stmt->bindParam(':to_date', $data['to_date'], PDO::PARAM_STR);
        $stmt->bindParam(':salary_sum_tax_state_enum', $data['salary_sum_tax_state_enum'], PDO::PARAM_STR);
        $stmt->execute();

        return $response->withHeader('Content-Type', 'application/json')
            ->write(json_encode(["message" => "Accounting year updated successfully"]));
    });

    // Delete an accounting year
    $app->delete('/{organizationId}/accountingyears/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $id = $args['id'];

        $stmt = $pdo->prepare("DELETE FROM accounting_year WHERE organization_id = :organizationId AND id = :id");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $response->withHeader('Content-Type', 'application/json')
            ->write(json_encode(["message" => "Accounting year deleted successfully"]));
    });

    /**
     * Ensure that the accounting_year table has an is_locked column. SQLite
     * doesn't allow simple IF EXISTS so we check table info before altering.
     */
    $ensureLockColumn = function (PDO $pdo) {
        $columns = [];
        $stmtInfo = $pdo->query("PRAGMA table_info(accounting_year)");
        $infoRows = $stmtInfo->fetchAll(PDO::FETCH_ASSOC);
        foreach ($infoRows as $col) {
            $columns[] = $col['name'];
        }
        if (!in_array('is_locked', $columns)) {
            $pdo->exec('ALTER TABLE accounting_year ADD COLUMN is_locked INTEGER DEFAULT 0');
        }
    };

    // Lock an accounting year
    $app->put('/{organizationId}/accountingyears/{id}/lock', function (Request $request, Response $response, array $args) use ($container, $ensureLockColumn) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $id = $args['id'];
        $ensureLockColumn($pdo);
        $stmt = $pdo->prepare("UPDATE accounting_year SET is_locked = 1 WHERE organization_id = :orgId AND id = :id");
        $stmt->execute([':orgId' => $organizationId, ':id' => $id]);
        return $response->withHeader('Content-Type', 'application/json')
            ->write(json_encode(["message" => "Accounting year locked"]));
    });

    // Unlock an accounting year
    $app->put('/{organizationId}/accountingyears/{id}/unlock', function (Request $request, Response $response, array $args) use ($container, $ensureLockColumn) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $id = $args['id'];
        $ensureLockColumn($pdo);
        $stmt = $pdo->prepare("UPDATE accounting_year SET is_locked = 0 WHERE organization_id = :orgId AND id = :id");
        $stmt->execute([':orgId' => $organizationId, ':id' => $id]);
        return $response->withHeader('Content-Type', 'application/json')
            ->write(json_encode(["message" => "Accounting year unlocked"]));
    });
};