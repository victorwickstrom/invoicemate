<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();
    
    // Hämta alla påminnelser för en faktura
    $app->get('/v1/{organizationId}/invoices/{voucherGuid}/reminders', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $voucherGuid = $args['voucherGuid'];

        $stmt = $pdo->prepare("SELECT * FROM reminder WHERE voucher_guid = ?");
        $stmt->execute([$voucherGuid]);
        $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($reminders));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Hämta en specifik påminnelse för en faktura
    $app->get('/v1/{organizationId}/invoices/{voucherGuid}/reminders/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $voucherGuid = $args['voucherGuid'];
        $reminderId = $args['id'];

        $stmt = $pdo->prepare("SELECT * FROM reminder WHERE voucher_guid = ? AND id = ?");
        $stmt->execute([$voucherGuid, $reminderId]);
        $reminder = $stmt->fetch(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($reminder));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Skapa en ny påminnelse
    $app->post('/v1/{organizationId}/invoices/{voucherGuid}/reminders', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $voucherGuid = $args['voucherGuid'];
        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("INSERT INTO reminder 
            (voucher_guid, organization_id, timestamp, date, title, description, is_draft, is_deleted, number, 
            with_debt_collection_warning, debt_collection_notice_text, with_fee, fee_amount, fee_amount_text, 
            with_interest_fee, interest_amount, interest_amount_text, with_compensation_fee, compensation_fee_amount, 
            compensation_fee_amount_text, compensation_fee_available, accumulated_fees_and_interest_amount, 
            accumulated_fees_and_interest_amount_text, invoice_total_incl_vat_amount, invoice_total_incl_vat_amount_text, 
            paid_amount, paid_amount_text, reminder_total_incl_vat_amount, reminder_total_incl_vat_amount_text) 
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $stmt->execute([
            $voucherGuid, $args['organizationId'], $data['timestamp'], $data['date'], $data['title'], $data['description'], 
            1, 0, $data['number'], $data['withDebtCollectionWarning'], $data['debtCollectionNoticeText'], 
            $data['withFee'], $data['feeAmount'], $data['feeAmountText'], $data['withInterestFee'], 
            $data['interestAmount'], $data['interestAmountText'], $data['withCompensationFee'], $data['compensationFeeAmount'], 
            $data['compensationFeeAmountText'], $data['compensationFeeAvailable'], $data['accumulatedFeesAndInterestAmount'], 
            $data['accumulatedFeesAndInterestAmountText'], $data['invoiceTotalInclVatAmount'], $data['invoiceTotalInclVatAmountText'], 
            $data['paidAmount'], $data['paidAmountText'], $data['reminderTotalInclVatAmount'], $data['reminderTotalInclVatAmountText']
        ]);

        $response->getBody()->write(json_encode(['message' => 'Reminder added successfully']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Uppdatera en påminnelse
    $app->put('/v1/{organizationId}/invoices/{voucherGuid}/reminders/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $reminderId = $args['id'];
        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("UPDATE reminder SET 
            timestamp = ?, date = ?, title = ?, description = ?, 
            with_debt_collection_warning = ?, with_fee = ?, fee_amount = ?, with_interest_fee = ?, 
            interest_amount = ?, with_compensation_fee = ?, compensation_fee_amount = ?, 
            accumulated_fees_and_interest_amount = ?, invoice_total_incl_vat_amount = ?, paid_amount = ?, 
            reminder_total_incl_vat_amount = ? WHERE id = ?");

        $stmt->execute([
            $data['timestamp'], $data['date'], $data['title'], $data['description'], 
            $data['withDebtCollectionWarning'], $data['withFee'], $data['feeAmount'], $data['withInterestFee'], 
            $data['interestAmount'], $data['withCompensationFee'], $data['compensationFeeAmount'], 
            $data['accumulatedFeesAndInterestAmount'], $data['invoiceTotalInclVatAmount'], $data['paidAmount'], 
            $data['reminderTotalInclVatAmount'], $reminderId
        ]);

        $response->getBody()->write(json_encode(['message' => 'Reminder updated successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Radera en påminnelse
    $app->delete('/v1/{organizationId}/invoices/{voucherGuid}/reminders/{id}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $reminderId = $args['id'];

        $stmt = $pdo->prepare("DELETE FROM reminder WHERE id = ?");
        $stmt->execute([$reminderId]);

        $response->getBody()->write(json_encode(['message' => 'Reminder deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Skicka en påminnelse via e-post
    $app->post('/v1/{organizationId}/invoices/{voucherGuid}/reminders/{id}/email', function (Request $request, Response $response, array $args) use ($container) {
        $data = $request->getParsedBody();

        // Simulerar att mejlet skickats
        $response->getBody()->write(json_encode(['message' => 'Reminder email sent successfully']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
};
