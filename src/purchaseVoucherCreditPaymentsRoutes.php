<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();
    
    // Hämta betalningar för en kreditköpsvoucher eller kreditnota
    $app->get('/v1/{organizationId}/purchase-vouchers/{id}/payments', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $voucherId = $args['id'];

        $stmt = $pdo->prepare("SELECT * FROM purchase_voucher_credit_payment WHERE purchase_voucher_guid = ?");
        $stmt->execute([$voucherId]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($payments));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Skapa en ny betalning för en kreditköpsvoucher eller kreditnota
    $app->post('/v1/{organizationId}/purchase-vouchers/{id}/payments', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $voucherId = $args['id'];
        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("INSERT INTO purchase_voucher_credit_payment 
            (guid, purchase_voucher_guid, external_reference, payment_date, description, amount, deposit_account_number, currency, exchange_rate, payment_class, related_voucher_guid, timestamp, amount_in_foreign_currency) 
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $stmt->execute([
            uniqid(), $voucherId, $data['externalReference'] ?? null, $data['paymentDate'], 
            $data['description'], $data['amount'], $data['depositAccountNumber'], 
            $data['currency'] ?? 'DKK', $data['exchangeRate'] ?? 100, 
            $data['paymentClass'] ?? null, $data['relatedVoucherId'] ?? null, 
            $data['timestamp'], $data['amountInForeignCurrency'] ?? null
        ]);

        $response->getBody()->write(json_encode(['message' => 'Payment added successfully']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Radera en betalning från en kreditköpsvoucher eller kreditnota
    $app->delete('/v1/{organizationId}/purchase-vouchers/{id}/payments/{paymentId}/{timestamp}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $paymentId = $args['paymentId'];

        $stmt = $pdo->prepare("DELETE FROM purchase_voucher_credit_payment WHERE guid = ?");
        $stmt->execute([$paymentId]);

        $response->getBody()->write(json_encode(['message' => 'Payment deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });
};
