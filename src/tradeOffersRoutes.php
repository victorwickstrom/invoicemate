<?php
/**
 * Routes for creating, retrieving and managing trade offers (quotations).
 *
 * Enhancements in this version include:
 *   - Resilient VAT lookup using correct column names (`vat_code`, `vat_rate`).
 *   - Unique number generation per organization. Although true concurrency
 *     control requires a database constraint, the code uses a simple
 *     sequence selection and should be paired with a unique index on
 *     (organization_id, number).
 *   - Endpoints to mark an offer as accepted or declined by the customer.
 *   - When converting an offer to an invoice, the offer's
 *     `generated_vouchers` column is updated with the created invoice GUID
 *     for traceability.
 */

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

return function ($app) {
    $container = $app->getContainer();

    /**
     * Compute totals for offer lines. Looks up VAT rates from vat_type
     * table using correct column names. Returns an array with totals and
     * processed lines containing computed monetary fields. Rounds
     * monetary amounts to two decimal places to avoid floating point
     * accumulation errors.
     *
     * @param \PDO $pdo
     * @param array $lines
     * @return array{0:array,1:array}
     */
    $computeOfferTotals = function (\PDO $pdo, array $lines) {
        // Build VAT mapping
        $vatMap = [];
        $stmtVat = $pdo->query('SELECT vat_code, vat_rate FROM vat_type');
        if ($stmtVat instanceof PDOStatement) {
            foreach ($stmtVat->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $vatMap[$row['vat_code']] = (float) $row['vat_rate'];
            }
        }
        $totals = [
            'total_excl_vat' => 0.0,
            'total_vatable_amount' => 0.0,
            'total_non_vatable_amount' => 0.0,
            'total_incl_vat' => 0.0,
            'total_vat' => 0.0,
        ];
        $processed = [];
        foreach ($lines as $line) {
            $qty = (float) ($line['quantity'] ?? 0);
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $discount = isset($line['discount']) ? (float) $line['discount'] : 0;
            $vatCode = $line['vat_code'] ?? null;
            $vatRate = $vatMap[$vatCode] ?? 0.0;
            $base = $qty * $unitPrice;
            if ($discount > 0) {
                $base = $base * (1 - $discount / 100.0);
            }
            $base = round($base, 2);
            $vatAmount = round($base * $vatRate, 2);
            $incl = round($base + $vatAmount, 2);
            $processed[] = [
                'product_guid' => $line['product_guid'] ?? null,
                'description' => $line['description'] ?? null,
                'quantity' => $qty,
                'unit' => $line['unit'] ?? null,
                'discount' => $discount,
                'vat_code' => $vatCode,
                'vat_rate' => $vatRate,
                'base_amount_value' => $base,
                'base_amount_value_incl_vat' => $incl,
                'total_amount' => $base,
                'total_amount_incl_vat' => $incl,
            ];
            $totals['total_excl_vat'] += $base;
            if ($vatRate > 0) {
                $totals['total_vatable_amount'] += $base;
            } else {
                $totals['total_non_vatable_amount'] += $base;
            }
            $totals['total_incl_vat'] += $incl;
            $totals['total_vat'] += $vatAmount;
        }
        // Round totals
        foreach ($totals as $key => $val) {
            $totals[$key] = round($val, 2);
        }
        return [$totals, $processed];
    };

    // List offers for an organization (paginated)
    $app->get('/v1/{organizationId}/offers', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $queryParams = $request->getQueryParams();
        $page = isset($queryParams['page']) ? max((int) $queryParams['page'], 0) : 0;
        $pageSize = isset($queryParams['pageSize']) ? max((int) $queryParams['pageSize'], 1) : 100;
        $stmt = $pdo->prepare('SELECT * FROM trade_offer WHERE organization_id = :orgId AND deleted_at IS NULL ORDER BY offer_date DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':orgId', $orgId);
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $page * $pageSize, PDO::PARAM_INT);
        $stmt->execute();
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode(['collection' => $offers, 'pagination' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'result' => count($offers),
        ]]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Create a new offer
    $app->post('/v1/{organizationId}/offers', function (Request $request, Response $response, array $args) use ($container, $computeOfferTotals) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $data = $request->getParsedBody();
        $lines = $data['lines'] ?? [];
        // Determine next offer number per organization
        $stmtNum = $pdo->prepare('SELECT COALESCE(MAX(number),0) + 1 AS next FROM trade_offer WHERE organization_id = :orgId');
        $stmtNum->execute([':orgId' => $orgId]);
        $nextNumber = (int) $stmtNum->fetchColumn();
        // Compute totals and processed lines
        [$totals, $processedLines] = $computeOfferTotals($pdo, $lines);
        $guid = $data['guid'] ?? uniqid('offer_', true);
        // Insert offer header
        $stmt = $pdo->prepare('INSERT INTO trade_offer (guid, organization_id, currency, language, external_reference, description, comment, offer_date, address, number, contact_name, contact_guid, show_lines_incl_vat, total_excl_vat, total_vatable_amount, total_incl_vat, total_non_vatable_amount, total_vat, invoice_template_id, status, created_at, updated_at, generated_vouchers) VALUES (:guid, :orgId, :currency, :language, :externalReference, :description, :comment, :offerDate, :address, :number, :contactName, :contactGuid, :showLinesInclVat, :totalExclVat, :totalVatableAmount, :totalInclVat, :totalNonVatableAmount, :totalVat, :invoiceTemplateId, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL)');
        $stmt->execute([
            ':guid' => $guid,
            ':orgId' => $orgId,
            ':currency' => $data['currency'] ?? 'DKK',
            ':language' => $data['language'] ?? 'da-DK',
            ':externalReference' => $data['externalReference'] ?? null,
            ':description' => $data['description'] ?? null,
            ':comment' => $data['comment'] ?? null,
            ':offerDate' => $data['date'] ?? date('Y-m-d'),
            ':address' => $data['address'] ?? null,
            ':number' => $nextNumber,
            ':contactName' => $data['contactName'] ?? null,
            ':contactGuid' => $data['contactGuid'] ?? null,
            ':showLinesInclVat' => isset($data['showLinesInclVat']) ? (int) $data['showLinesInclVat'] : 0,
            ':totalExclVat' => $totals['total_excl_vat'],
            ':totalVatableAmount' => $totals['total_vatable_amount'],
            ':totalInclVat' => $totals['total_incl_vat'],
            ':totalNonVatableAmount' => $totals['total_non_vatable_amount'],
            ':totalVat' => $totals['total_vat'],
            ':invoiceTemplateId' => $data['invoiceTemplateId'] ?? null,
            ':status' => 'Draft',
        ]);
        // Insert lines into trade_offer_lines
        $stmtLine = $pdo->prepare('INSERT INTO trade_offer_lines (trade_offer_guid, product_guid, description, quantity, unit, discount, vat_code, vat_rate, base_amount_value, base_amount_value_incl_vat, total_amount, total_amount_incl_vat) VALUES (:offerGuid, :productGuid, :description, :quantity, :unit, :discount, :vatCode, :vatRate, :baseAmount, :baseAmountIncl, :totalAmount, :totalAmountIncl)');
        foreach ($processedLines as $line) {
            $stmtLine->execute([
                ':offerGuid' => $guid,
                ':productGuid' => $line['product_guid'],
                ':description' => $line['description'],
                ':quantity' => $line['quantity'],
                ':unit' => $line['unit'],
                ':discount' => $line['discount'],
                ':vatCode' => $line['vat_code'],
                ':vatRate' => $line['vat_rate'],
                ':baseAmount' => $line['base_amount_value'],
                ':baseAmountIncl' => $line['base_amount_value_incl_vat'],
                ':totalAmount' => $line['total_amount'],
                ':totalAmountIncl' => $line['total_amount_incl_vat'],
            ]);
        }
        $response->getBody()->write(json_encode([
            'message' => 'Offer created successfully',
            'guid' => $guid,
            'number' => $nextNumber,
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Retrieve an offer (with lines)
    $app->get('/v1/{organizationId}/offers/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        $stmt = $pdo->prepare('SELECT * FROM trade_offer WHERE organization_id = :orgId AND guid = :guid AND deleted_at IS NULL');
        $stmt->execute([':orgId' => $orgId, ':guid' => $guid]);
        $offer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$offer) {
            $response->getBody()->write(json_encode(['error' => 'Offer not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        // Fetch lines
        $stmtLines = $pdo->prepare('SELECT product_guid, description, quantity, unit, discount, vat_code, vat_rate, base_amount_value, base_amount_value_incl_vat, total_amount, total_amount_incl_vat FROM trade_offer_lines WHERE trade_offer_guid = :guid');
        $stmtLines->execute([':guid' => $guid]);
        $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
        $offer['lines'] = $lines;
        $response->getBody()->write(json_encode($offer));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Update an offer (replace lines and recalc totals)
    $app->put('/v1/{organizationId}/offers/{guid}', function (Request $request, Response $response, array $args) use ($container, $computeOfferTotals) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        $data = $request->getParsedBody();
        $lines = $data['lines'] ?? [];
        // Compute totals
        [$totals, $processedLines] = $computeOfferTotals($pdo, $lines);
        // Update offer header
        $stmt = $pdo->prepare('UPDATE trade_offer SET external_reference = :externalReference, description = :description, comment = :comment, offer_date = :offerDate, address = :address, contact_name = :contactName, contact_guid = :contactGuid, show_lines_incl_vat = :showLinesInclVat, total_excl_vat = :totalExclVat, total_vatable_amount = :totalVatableAmount, total_incl_vat = :totalInclVat, total_non_vatable_amount = :totalNonVatableAmount, total_vat = :totalVat, invoice_template_id = :invoiceTemplateId, updated_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND guid = :guid');
        $stmt->execute([
            ':externalReference' => $data['externalReference'] ?? null,
            ':description' => $data['description'] ?? null,
            ':comment' => $data['comment'] ?? null,
            ':offerDate' => $data['date'] ?? date('Y-m-d'),
            ':address' => $data['address'] ?? null,
            ':contactName' => $data['contactName'] ?? null,
            ':contactGuid' => $data['contactGuid'] ?? null,
            ':showLinesInclVat' => isset($data['showLinesInclVat']) ? (int) $data['showLinesInclVat'] : 0,
            ':totalExclVat' => $totals['total_excl_vat'],
            ':totalVatableAmount' => $totals['total_vatable_amount'],
            ':totalInclVat' => $totals['total_incl_vat'],
            ':totalNonVatableAmount' => $totals['total_non_vatable_amount'],
            ':totalVat' => $totals['total_vat'],
            ':invoiceTemplateId' => $data['invoiceTemplateId'] ?? null,
            ':orgId' => $orgId,
            ':guid' => $guid,
        ]);
        // Replace lines
        $pdo->prepare('DELETE FROM trade_offer_lines WHERE trade_offer_guid = :guid')->execute([':guid' => $guid]);
        $stmtLine = $pdo->prepare('INSERT INTO trade_offer_lines (trade_offer_guid, product_guid, description, quantity, unit, discount, vat_code, vat_rate, base_amount_value, base_amount_value_incl_vat, total_amount, total_amount_incl_vat) VALUES (:offerGuid, :productGuid, :description, :quantity, :unit, :discount, :vatCode, :vatRate, :baseAmount, :baseAmountIncl, :totalAmount, :totalAmountIncl)');
        foreach ($processedLines as $line) {
            $stmtLine->execute([
                ':offerGuid' => $guid,
                ':productGuid' => $line['product_guid'],
                ':description' => $line['description'],
                ':quantity' => $line['quantity'],
                ':unit' => $line['unit'],
                ':discount' => $line['discount'],
                ':vatCode' => $line['vat_code'],
                ':vatRate' => $line['vat_rate'],
                ':baseAmount' => $line['base_amount_value'],
                ':baseAmountIncl' => $line['base_amount_value_incl_vat'],
                ':totalAmount' => $line['total_amount'],
                ':totalAmountIncl' => $line['total_amount_incl_vat'],
            ]);
        }
        $response->getBody()->write(json_encode(['message' => 'Offer updated successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Soft delete an offer
    $app->delete('/v1/{organizationId}/offers/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        $stmt = $pdo->prepare('UPDATE trade_offer SET deleted_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND guid = :guid');
        $stmt->execute([':orgId' => $orgId, ':guid' => $guid]);
        $response->getBody()->write(json_encode(['message' => 'Offer deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Endpoint: accept an offer (customer accepted)
    $app->post('/v1/{organizationId}/offers/{guid}/accept', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        $stmt = $pdo->prepare('UPDATE trade_offer SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND guid = :guid AND deleted_at IS NULL');
        $stmt->execute([':status' => 'CustomerAccepted', ':orgId' => $orgId, ':guid' => $guid]);
        $response->getBody()->write(json_encode(['message' => 'Offer accepted']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Endpoint: decline an offer (customer declined)
    $app->post('/v1/{organizationId}/offers/{guid}/decline', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        $stmt = $pdo->prepare('UPDATE trade_offer SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND guid = :guid AND deleted_at IS NULL');
        $stmt->execute([':status' => 'CustomerDeclined', ':orgId' => $orgId, ':guid' => $guid]);
        $response->getBody()->write(json_encode(['message' => 'Offer declined']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Convert an accepted offer to an invoice
    $app->post('/v1/{organizationId}/offers/{guid}/invoice', function (Request $request, Response $response, array $args) use ($container, $computeOfferTotals) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['guid'];
        // Fetch offer
        $stmtOffer = $pdo->prepare('SELECT * FROM trade_offer WHERE organization_id = :orgId AND guid = :guid AND deleted_at IS NULL');
        $stmtOffer->execute([':orgId' => $orgId, ':guid' => $guid]);
        $offer = $stmtOffer->fetch(PDO::FETCH_ASSOC);
        if (!$offer) {
            $response->getBody()->write(json_encode(['error' => 'Offer not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        // Fetch lines
        $stmtLines = $pdo->prepare('SELECT * FROM trade_offer_lines WHERE trade_offer_guid = :guid');
        $stmtLines->execute([':guid' => $guid]);
        $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
        // Compute totals for invoice lines
        [$totals, $processed] = $computeOfferTotals($pdo, $lines);
        $invoiceGuid = uniqid('inv_', true);
        // Determine next invoice number per organization
        $stmtNum = $pdo->prepare('SELECT COALESCE(MAX(number),0)+1 AS next FROM invoice WHERE organization_id = :orgId');
        $stmtNum->execute([':orgId' => $orgId]);
        $nextNumber = (int) $stmtNum->fetchColumn();
        // Insert invoice header
        $stmtIns = $pdo->prepare('INSERT INTO invoice (guid, organization_id, currency, language, external_reference, description, comment, invoice_date, due_date, address, number, contact_name, contact_guid, show_lines_incl_vat, total_excl_vat, total_vatable_amount, total_incl_vat, total_non_vatable_amount, total_vat, invoice_template_id, status, created_at, updated_at) VALUES (:guid, :orgId, :currency, :language, :externalReference, :description, :comment, :invoiceDate, :dueDate, :address, :number, :contactName, :contactGuid, :showLinesInclVat, :totalExclVat, :totalVatableAmount, :totalInclVat, :totalNonVatableAmount, :totalVat, :invoiceTemplateId, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $dueDate = date('Y-m-d', strtotime('+14 days'));
        $stmtIns->execute([
            ':guid' => $invoiceGuid,
            ':orgId' => $orgId,
            ':currency' => $offer['currency'] ?? 'DKK',
            ':language' => $offer['language'] ?? 'da-DK',
            ':externalReference' => $offer['external_reference'] ?? null,
            ':description' => $offer['description'] ?? null,
            ':comment' => $offer['comment'] ?? null,
            ':invoiceDate' => date('Y-m-d'),
            ':dueDate' => $dueDate,
            ':address' => $offer['address'] ?? null,
            ':number' => $nextNumber,
            ':contactName' => $offer['contact_name'] ?? null,
            ':contactGuid' => $offer['contact_guid'] ?? null,
            ':showLinesInclVat' => $offer['show_lines_incl_vat'] ?? 0,
            ':totalExclVat' => $totals['total_excl_vat'],
            ':totalVatableAmount' => $totals['total_vatable_amount'],
            ':totalInclVat' => $totals['total_incl_vat'],
            ':totalNonVatableAmount' => $totals['total_non_vatable_amount'],
            ':totalVat' => $totals['total_vat'],
            ':invoiceTemplateId' => $offer['invoice_template_id'] ?? null,
            ':status' => 'Draft',
        ]);
        // Insert invoice lines
        $stmtLine = $pdo->prepare('INSERT INTO invoice_lines (invoice_guid, product_guid, description, quantity, unit, discount, vat_code, vat_rate, base_amount_value, base_amount_value_incl_vat, total_amount, total_amount_incl_vat) VALUES (:invoiceGuid, :productGuid, :description, :quantity, :unit, :discount, :vatCode, :vatRate, :baseAmount, :baseAmountIncl, :totalAmount, :totalAmountIncl)');
        foreach ($processed as $line) {
            $stmtLine->execute([
                ':invoiceGuid' => $invoiceGuid,
                ':productGuid' => $line['product_guid'],
                ':description' => $line['description'],
                ':quantity' => $line['quantity'],
                ':unit' => $line['unit'],
                ':discount' => $line['discount'],
                ':vatCode' => $line['vat_code'],
                ':vatRate' => $line['vat_rate'],
                ':baseAmount' => $line['base_amount_value'],
                ':baseAmountIncl' => $line['base_amount_value_incl_vat'],
                ':totalAmount' => $line['total_amount'],
                ':totalAmountIncl' => $line['total_amount_incl_vat'],
            ]);
        }
        // Update offer status and generated_vouchers
        $pdo->prepare('UPDATE trade_offer SET status = :status, generated_vouchers = :generatedVouchers WHERE organization_id = :orgId AND guid = :guid')->execute([
            ':status' => 'Invoiced',
            ':generatedVouchers' => $invoiceGuid,
            ':orgId' => $orgId,
            ':guid' => $guid,
        ]);
        $response->getBody()->write(json_encode([
            'message' => 'Offer converted to invoice successfully',
            'invoice_guid' => $invoiceGuid,
            'invoice_number' => $nextNumber,
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });
};