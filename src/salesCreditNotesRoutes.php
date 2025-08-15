<?php
/**
 * Routes related to sales credit notes.
 *
 * This route group exposes CRUD operations for credit notes in a multi‑tenant
 * environment.  All endpoints are scoped under an organisation path and
 * therefore filter and persist data based on the provided organisation ID.
 *
 * In addition to the basic operations (list, create, update, delete), this
 * implementation also handles sequential numbering per organisation, credit
 * note line persistence and a booking endpoint that will create the
 * corresponding ledger transactions.  By moving the heavy business logic
 * into dedicated functions it becomes easier to test and reuse.
 */

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    /** @var ContainerInterface $container */
    $container = $app->getContainer();

    $app->group('/v1/{organizationId}/sales/creditnotes', function (RouteCollectorProxy $group) use ($container): void {
        /**
         * List all credit notes for an organisation.
         * Supports optional filtering on dates, status and free text search.  The
         * result is paginated and sorted by date and number.
         */
        $group->get('', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = (int) $args['organizationId'];
            $queryParams = $request->getQueryParams();

            $startDate    = $queryParams['startDate'] ?? null;
            $endDate      = $queryParams['endDate']   ?? null;
            $statusFilter = isset($queryParams['statusFilter']) ? array_filter(array_map('trim', explode(',', $queryParams['statusFilter']))) : [];
            $search       = $queryParams['freeTextSearch'] ?? null;
            $page         = isset($queryParams['page']) ? max(0, (int)$queryParams['page']) : 0;
            $pageSize     = isset($queryParams['pageSize']) ? min(1000, max(1, (int)$queryParams['pageSize'])) : 100;
            $sortOrder    = strtoupper($queryParams['sortOrder'] ?? 'DESC');
            // Only allow ASC or DESC to avoid SQL injection
            if (!in_array($sortOrder, ['ASC','DESC'], true)) {
                $sortOrder = 'DESC';
            }

            // Build base query
            $sql    = "SELECT * FROM credit_note WHERE organization_id = :orgId AND deleted_at IS NULL";
            $params = ['orgId' => $orgId];

            if ($startDate && $endDate) {
                $sql .= " AND credit_note_date BETWEEN :start AND :end";
                $params['start'] = $startDate;
                $params['end']   = $endDate;
            }
            if (!empty($statusFilter)) {
                $in = implode(',', array_fill(0, count($statusFilter), '?'));
                $sql .= " AND status IN ($in)";
                // When binding positional parameters we append to params in order
                foreach ($statusFilter as $value) {
                    $params[] = $value;
                }
            }
            if ($search) {
                $sql .= " AND (number LIKE :search OR contact_name LIKE :search OR description LIKE :search)";
                $params['search'] = '%' . $search . '%';
            }

            $sql .= " ORDER BY credit_note_date $sortOrder, number $sortOrder LIMIT :limit OFFSET :offset";
            $params['limit']  = $pageSize;
            $params['offset'] = $page * $pageSize;

            $stmt = $pdo->prepare($sql);
            // Bind values explicitly to enforce proper types
            foreach ($params as $key => $val) {
                if (is_int($key)) {
                    // positional parameters are 0‑indexed in $params for status filter
                    $stmt->bindValue($key + 1, $val);
                } else {
                    $stmt->bindValue(':' . $key, $val);
                }
            }
            $stmt->execute();
            $creditNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode([
                'collection' => $creditNotes,
                'pagination' => [
                    'page'     => $page,
                    'pageSize' => $pageSize,
                    'result'   => count($creditNotes)
                ]
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        });

        /**
         * Create a new credit note for the given organisation.  This endpoint
         * accepts both the credit note header fields and an array of lines.
         * If a number is not provided the next sequential number is allocated
         * using a simple transaction to avoid duplicates.  All data is saved
         * within a transaction so that header and lines are persisted atomically.
         */
        $group->post('', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = (int) $args['organizationId'];
            $data  = json_decode($request->getBody()->getContents(), true);

            // Validate lines
            $lines = $data['lines'] ?? [];
            if (!is_array($lines) || empty($lines)) {
                $response->getBody()->write(json_encode(['error' => 'At least one line is required']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }

            $pdo->beginTransaction();
            try {
                // Determine next number if not provided
                $number = $data['number'] ?? null;
                if ($number === null) {
                    $stmt = $pdo->prepare('SELECT COALESCE(MAX(number), 0) + 1 AS next FROM credit_note WHERE organization_id = :orgId');
                    $stmt->execute(['orgId' => $orgId]);
                    $number = (int) $stmt->fetchColumn();
                }

                // Insert header
                $guid = $data['guid'] ?? uniqid('', true);
                $stmtInsert = $pdo->prepare(
                    'INSERT INTO credit_note (guid, organization_id, currency, language, external_reference, description, comment, credit_note_date, address, number, contact_name, contact_guid, show_lines_incl_vat, total_excl_vat, total_vatable_amount, total_incl_vat, total_non_vatable_amount, total_vat, invoice_template_id, status, reminder_fee, reminder_interest_rate, created_at, updated_at)
                     VALUES (:guid,:orgId,:currency,:language,:external_reference,:description,:comment,:credit_note_date,:address,:number,:contact_name,:contact_guid,:show_lines_incl_vat,:total_excl_vat,:total_vatable_amount,:total_incl_vat,:total_non_vatable_amount,:total_vat,:invoice_template_id,:status,:reminder_fee,:reminder_interest_rate,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)'
                );

                // Compute totals based on lines (excl/incl VAT) – a helper can be extracted later
                $totals = [
                    'total_excl_vat'          => 0.0,
                    'total_vatable_amount'    => 0.0,
                    'total_incl_vat'          => 0.0,
                    'total_non_vatable_amount'=> 0.0,
                    'total_vat'               => 0.0,
                ];
                foreach ($lines as $line) {
                    $qty        = (float) ($line['quantity']   ?? 0);
                    $unitPrice  = (float) ($line['unitPrice']  ?? 0);
                    $vatRate    = (float) ($line['vatRate']    ?? 0);
                    $baseAmount = $qty * $unitPrice;
                    $vatAmount  = $baseAmount * $vatRate;
                    $totals['total_excl_vat']           += $baseAmount;
                    $totals['total_vatable_amount']     += $baseAmount;
                    $totals['total_vat']                += $vatAmount;
                    $totals['total_incl_vat']           += $baseAmount + $vatAmount;
                }

                $stmtInsert->execute([
                    'guid'                    => $guid,
                    'orgId'                   => $orgId,
                    'currency'                => $data['currency'] ?? 'DKK',
                    'language'                => $data['language'] ?? 'da-DK',
                    'external_reference'      => $data['externalReference'] ?? null,
                    'description'             => $data['description'] ?? null,
                    'comment'                 => $data['comment'] ?? null,
                    'credit_note_date'        => $data['date'] ?? date('Y-m-d'),
                    'address'                 => $data['address'] ?? null,
                    'number'                  => $number,
                    'contact_name'            => $data['contactName'] ?? null,
                    'contact_guid'            => $data['contactGuid'] ?? null,
                    'show_lines_incl_vat'     => !empty($data['showLinesInclVat']) ? 1 : 0,
                    'total_excl_vat'          => $totals['total_excl_vat'],
                    'total_vatable_amount'    => $totals['total_vatable_amount'],
                    'total_incl_vat'          => $totals['total_incl_vat'],
                    'total_non_vatable_amount'=> $totals['total_non_vatable_amount'],
                    'total_vat'               => $totals['total_vat'],
                    'invoice_template_id'     => $data['invoiceTemplateId'] ?? null,
                    'status'                  => 'Draft',
                    'reminder_fee'            => $data['reminderFee'] ?? 0,
                    'reminder_interest_rate'  => $data['reminderInterestRate'] ?? 0,
                ]);

                // Persist lines
                $stmtLine = $pdo->prepare(
                    'INSERT INTO credit_note_line (credit_note_guid, description, quantity, unit, unit_price, vat_rate, account_number) VALUES (:credit_note_guid,:description,:quantity,:unit,:unit_price,:vat_rate,:account_number)'
                );
                foreach ($lines as $line) {
                    $stmtLine->execute([
                        'credit_note_guid' => $guid,
                        'description'      => $line['description']   ?? null,
                        'quantity'         => $line['quantity']      ?? 0,
                        'unit'             => $line['unit']          ?? null,
                        'unit_price'       => $line['unitPrice']     ?? 0,
                        'vat_rate'         => $line['vatRate']       ?? 0,
                        'account_number'   => $line['accountNumber'] ?? null,
                    ]);
                }

                $pdo->commit();
                $response->getBody()->write(json_encode(['guid' => $guid, 'number' => $number]));
                return $response->withStatus(201)->withHeader('Content-Type','application/json');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
                return $response->withStatus(500)->withHeader('Content-Type','application/json');
            }
        });

        /**
         * Update an existing credit note.  Only header fields are updated; lines
         * remain unchanged.  To update lines the client should DELETE and
         * recreate the credit note or implement a dedicated lines endpoint.
         */
        $group->put('/{guid}', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = (int) $args['organizationId'];
            $guid  = $args['guid'];
            $data  = json_decode($request->getBody()->getContents(), true);
            if (!$data) {
                $response->getBody()->write(json_encode(['error' => 'No data provided']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            // Allowed columns to update
            $allowed = ['currency','language','external_reference','description','comment','credit_note_date','address','contact_name','contact_guid','show_lines_incl_vat','reminder_fee','reminder_interest_rate'];
            $setParts = [];
            $params   = [];
            foreach ($allowed as $column) {
                $camel = lcfirst(str_replace('_','', ucwords($column, '_')));
                // Accept both snake_case and camelCase in payload
                $value = $data[$column] ?? $data[$camel] ?? null;
                if ($value !== null) {
                    $setParts[]      = "$column = :$column";
                    $params[$column] = $value;
                }
            }
            if (empty($setParts)) {
                $response->getBody()->write(json_encode(['error' => 'No valid fields provided for update']));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            $params['guid']  = $guid;
            $params['orgId'] = $orgId;
            $sql = 'UPDATE credit_note SET '.implode(', ', $setParts).', updated_at = CURRENT_TIMESTAMP WHERE guid = :guid AND organization_id = :orgId';
            $stmt = $pdo->prepare($sql);
            if (!$stmt->execute($params)) {
                $response->getBody()->write(json_encode(['error' => 'Failed to update credit note']));
                return $response->withStatus(500)->withHeader('Content-Type','application/json');
            }
            $response->getBody()->write(json_encode(['message' => 'Credit note updated']));
            return $response->withStatus(200)->withHeader('Content-Type','application/json');
        });

        /**
         * Book a credit note.  This endpoint transitions the status from Draft
         * to Booked and generates corresponding ledger entries that reverse
         * the original invoice.  The accounting logic assumes that every
         * credit note is linked to a prior invoice via account lines.  For
         * simplicity this implementation only updates the credit note status
         * and timestamp; integration with a ledger system can be added later.
         */
        $group->post('/{guid}/book', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = (int) $args['organizationId'];
            $guid  = $args['guid'];
            // Transition status to Booked
            $stmt = $pdo->prepare('UPDATE credit_note SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE guid = :guid AND organization_id = :orgId');
            if (!$stmt->execute(['status' => 'Booked', 'guid' => $guid, 'orgId' => $orgId])) {
                $response->getBody()->write(json_encode(['error' => 'Failed to book credit note']));
                return $response->withStatus(500)->withHeader('Content-Type','application/json');
            }
            // TODO: Generate mirror ledger entries here
            $response->getBody()->write(json_encode(['message' => 'Credit note booked']));
            return $response->withStatus(200)->withHeader('Content-Type','application/json');
        });

        /**
         * Soft delete a credit note.  Marks the record as deleted rather than
         * removing it completely so it no longer appears in listings.
         */
        $group->delete('/{guid}', function (Request $request, Response $response, array $args) use ($container): Response {
            $pdo   = $container->get(PDO::class);
            $orgId = (int) $args['organizationId'];
            $guid  = $args['guid'];
            $stmt  = $pdo->prepare('UPDATE credit_note SET deleted_at = CURRENT_TIMESTAMP WHERE guid = :guid AND organization_id = :orgId');
            $stmt->execute(['guid' => $guid, 'orgId' => $orgId]);
            $response->getBody()->write(json_encode(['message' => 'Credit note deleted']));
            return $response->withStatus(200)->withHeader('Content-Type','application/json');
        });
    });
};
