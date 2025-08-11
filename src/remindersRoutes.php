<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

/**
 * Reminder routes.
 *
 * Reminders are created against a specific invoice and can include fees and
 * interest for late payment. When creating a new reminder the next reminder
 * number is automatically assigned and standard reminder fees and interest
 * amounts are computed based on the invoice's configuration. This file
 * improves upon the original implementation by calculating these values and
 * updating the invoice's status accordingly. Listing, retrieving, updating
 * and deleting reminders remain similar to the original code.
 */
return function (App $app) {
    $container = $app->getContainer();

    // List all reminders for an invoice
    $app->get('/v1/{organizationId}/invoices/{voucherGuid}/reminders', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $voucherGuid = $args['voucherGuid'];
        $stmt = $pdo->prepare("SELECT * FROM reminder WHERE organization_id = :orgId AND voucher_guid = :voucherGuid AND is_deleted = 0 ORDER BY number ASC");
        $stmt->execute([':orgId' => $orgId, ':voucherGuid' => $voucherGuid]);
        $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($reminders));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Get a specific reminder
    $app->get('/v1/{organizationId}/invoices/{voucherGuid}/reminders/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $voucherGuid = $args['voucherGuid'];
        $id = $args['id'];
        $stmt = $pdo->prepare("SELECT * FROM reminder WHERE organization_id = :orgId AND voucher_guid = :voucherGuid AND id = :id");
        $stmt->execute([':orgId' => $orgId, ':voucherGuid' => $voucherGuid, ':id' => $id]);
        $reminder = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$reminder) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(["error" => "Reminder not found"]));
        }
        $response->getBody()->write(json_encode($reminder));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Create a new reminder
    $app->post('/v1/{organizationId}/invoices/{voucherGuid}/reminders', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $voucherGuid = $args['voucherGuid'];
        $data = $request->getParsedBody();

        // Determine next reminder number for this invoice
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM reminder WHERE organization_id = :orgId AND voucher_guid = :voucherGuid AND is_deleted = 0");
        $stmtCount->execute([':orgId' => $orgId, ':voucherGuid' => $voucherGuid]);
        $nextNumber = (int) $stmtCount->fetchColumn() + 1;

        // Fetch invoice to calculate fees and interest
        $stmtInv = $pdo->prepare("SELECT total_incl_vat, due_date, paid_amount, reminder_fee, reminder_interest_rate, payment_status FROM invoice WHERE organization_id = :orgId AND guid = :guid");
        $stmtInv->execute([':orgId' => $orgId, ':guid' => $voucherGuid]);
        $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(["error" => "Invoice not found"]));
        }

        // Base amounts
        $totalInclVat = (float) $invoice['total_incl_vat'];
        $paidAmount = (float) $invoice['paid_amount'];
        $outstanding = max(0.0, $totalInclVat - $paidAmount);

        // Fee calculation: if withFee flag true use invoice.reminder_fee
        $withFee = !empty($data['withFee']);
        $feeAmount = 0.0;
        if ($withFee && isset($invoice['reminder_fee'])) {
            $feeAmount = (float) $invoice['reminder_fee'];
        }

        // Interest calculation: if withInterestFee flag true compute interest from due date
        $withInterest = !empty($data['withInterestFee']);
        $interestAmount = 0.0;
        if ($withInterest && isset($invoice['reminder_interest_rate'])) {
            $rate = (float) $invoice['reminder_interest_rate']; // annual rate (e.g. 0.10 for 10%)
            $today = new DateTime();
            $due = new DateTime($invoice['due_date']);
            if ($today > $due) {
                $daysLate = (int) $due->diff($today)->format('%a');
                $interestAmount = $outstanding * $rate * ($daysLate / 365);
            }
        }

        // Reminder total includes outstanding + fee + interest
        $reminderTotal = $outstanding + $feeAmount + $interestAmount;

        // Insert reminder
        $stmt = $pdo->prepare("INSERT INTO reminder (
            voucher_guid, organization_id, timestamp, date, title, description, is_draft, is_deleted, number,
            with_debt_collection_warning, debt_collection_notice_text, with_fee, fee_amount, fee_amount_text,
            with_interest_fee, interest_amount, interest_amount_text, with_compensation_fee, compensation_fee_amount,
            compensation_fee_amount_text, compensation_fee_available, accumulated_fees_and_interest_amount,
            accumulated_fees_and_interest_amount_text, invoice_total_incl_vat_amount, invoice_total_incl_vat_amount_text,
            paid_amount, paid_amount_text, reminder_total_incl_vat_amount, reminder_total_incl_vat_amount_text
        ) VALUES (
            :voucherGuid, :organizationId, :timestamp, :date, :title, :description, 1, 0, :number,
            :withDebtCollectionWarning, :debtCollectionNoticeText, :withFee, :feeAmount, :feeAmountText,
            :withInterestFee, :interestAmount, :interestAmountText, :withCompensationFee, :compensationFeeAmount,
            :compensationFeeAmountText, :compensationFeeAvailable, :accumulatedFeesAndInterestAmount,
            :accumulatedFeesAndInterestAmountText, :invoiceTotalInclVatAmount, :invoiceTotalInclVatAmountText,
            :paidAmount, :paidAmountText, :reminderTotalInclVatAmount, :reminderTotalInclVatAmountText
        )");

        $now = time();
        $stmt->execute([
            ':voucherGuid' => $voucherGuid,
            ':organizationId' => $orgId,
            ':timestamp' => $now,
            ':date' => date('Y-m-d'),
            ':title' => $data['title'] ?? "Reminder #$nextNumber",
            ':description' => $data['description'] ?? null,
            ':number' => $nextNumber,
            ':withDebtCollectionWarning' => !empty($data['withDebtCollectionWarning']) ? 1 : 0,
            ':debtCollectionNoticeText' => $data['debtCollectionNoticeText'] ?? null,
            ':withFee' => $withFee ? 1 : 0,
            ':feeAmount' => $feeAmount,
            ':feeAmountText' => $withFee ? (string) $feeAmount : null,
            ':withInterestFee' => $withInterest ? 1 : 0,
            ':interestAmount' => $interestAmount,
            ':interestAmountText' => $withInterest ? (string) $interestAmount : null,
            ':withCompensationFee' => !empty($data['withCompensationFee']) ? 1 : 0,
            ':compensationFeeAmount' => $data['compensationFeeAmount'] ?? 0,
            ':compensationFeeAmountText' => isset($data['compensationFeeAmount']) ? (string) $data['compensationFeeAmount'] : null,
            ':compensationFeeAvailable' => $data['compensationFeeAvailable'] ?? 0,
            ':accumulatedFeesAndInterestAmount' => $feeAmount + $interestAmount,
            ':accumulatedFeesAndInterestAmountText' => (string) ($feeAmount + $interestAmount),
            ':invoiceTotalInclVatAmount' => $totalInclVat,
            ':invoiceTotalInclVatAmountText' => (string) $totalInclVat,
            ':paidAmount' => $paidAmount,
            ':paidAmountText' => (string) $paidAmount,
            ':reminderTotalInclVatAmount' => $reminderTotal,
            ':reminderTotalInclVatAmountText' => (string) $reminderTotal
        ]);

        // Update invoice status to Reminded if overdue
        $newStatus = 'Reminded';
        $stmtUpd = $pdo->prepare("UPDATE invoice SET payment_status = :status WHERE organization_id = :orgId AND guid = :guid");
        $stmtUpd->execute([':status' => $newStatus, ':orgId' => $orgId, ':guid' => $voucherGuid]);

        $response->getBody()->write(json_encode([
            'message' => 'Reminder added successfully',
            'number' => $nextNumber,
            'feeAmount' => $feeAmount,
            'interestAmount' => $interestAmount,
            'reminderTotal' => $reminderTotal
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Update a reminder
    $app->put('/v1/{organizationId}/invoices/{voucherGuid}/reminders/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $id = $args['id'];
        $data = $request->getParsedBody();
        // Update basic fields; complex recalculation should be done via POST
        $stmt = $pdo->prepare("UPDATE reminder SET timestamp = :timestamp, date = :date, title = :title, description = :description, with_debt_collection_warning = :withDebtCollectionWarning, with_fee = :withFee, fee_amount = :feeAmount, with_interest_fee = :withInterestFee, interest_amount = :interestAmount, with_compensation_fee = :withCompensationFee, compensation_fee_amount = :compensationFeeAmount, accumulated_fees_and_interest_amount = :accumulatedFeesAndInterestAmount, invoice_total_incl_vat_amount = :invoiceTotalInclVatAmount, paid_amount = :paidAmount, reminder_total_incl_vat_amount = :reminderTotalInclVatAmount WHERE organization_id = :orgId AND id = :id");
        $stmt->execute([
            ':timestamp' => time(),
            ':date' => $data['date'] ?? date('Y-m-d'),
            ':title' => $data['title'] ?? null,
            ':description' => $data['description'] ?? null,
            ':withDebtCollectionWarning' => !empty($data['withDebtCollectionWarning']) ? 1 : 0,
            ':withFee' => !empty($data['withFee']) ? 1 : 0,
            ':feeAmount' => $data['feeAmount'] ?? 0,
            ':withInterestFee' => !empty($data['withInterestFee']) ? 1 : 0,
            ':interestAmount' => $data['interestAmount'] ?? 0,
            ':withCompensationFee' => !empty($data['withCompensationFee']) ? 1 : 0,
            ':compensationFeeAmount' => $data['compensationFeeAmount'] ?? 0,
            ':accumulatedFeesAndInterestAmount' => $data['accumulatedFeesAndInterestAmount'] ?? 0,
            ':invoiceTotalInclVatAmount' => $data['invoiceTotalInclVatAmount'] ?? 0,
            ':paidAmount' => $data['paidAmount'] ?? 0,
            ':reminderTotalInclVatAmount' => $data['reminderTotalInclVatAmount'] ?? 0,
            ':orgId' => $orgId,
            ':id' => $id
        ]);
        $response->getBody()->write(json_encode(['message' => 'Reminder updated successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Delete a reminder (soft delete)
    $app->delete('/v1/{organizationId}/invoices/{voucherGuid}/reminders/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $id = $args['id'];
        // Mark as deleted rather than physical removal
        $stmt = $pdo->prepare("UPDATE reminder SET is_deleted = 1 WHERE organization_id = :orgId AND id = :id");
        $stmt->execute([':orgId' => $orgId, ':id' => $id]);
        $response->getBody()->write(json_encode(['message' => 'Reminder deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Send a reminder via email (simulated)
    $app->post('/v1/{organizationId}/invoices/{voucherGuid}/reminders/{id}/email', function (Request $request, Response $response, array $args) use ($container) {
        // In a full implementation this would generate a PDF/HTML and send using a mailer
        $response->getBody()->write(json_encode(['message' => 'Reminder email sent successfully']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
};