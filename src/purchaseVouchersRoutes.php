<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Hämta en inköpsfaktura baserat på GUID
    $app->get('/v1/{organizationId}/vouchers/purchase/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $voucherGuid = $args['guid'];

        $stmt = $pdo->prepare("SELECT * FROM purchase_voucher WHERE guid = ?");
        $stmt->execute([$voucherGuid]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($voucher));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Skapa en ny inköpsfaktura
    $app->post('/v1/{organizationId}/vouchers/purchase', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("INSERT INTO purchase_voucher 
            (guid, voucher_date, payment_date, status, purchase_type, deposit_account_number, region_key, external_reference, currency_key, exchange_rate, booked_by_type, booked_by_username, booking_time, contact_guid, file_guid, timestamp, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $stmt->execute([
            uniqid(), $data['voucherDate'], $data['paymentDate'] ?? null, 'Draft', $data['purchaseType'], 
            $data['depositAccountNumber'], $data['regionKey'] ?? null, 
            $data['externalReference'] ?? null, $data['currencyKey'] ?? 'DKK', 100, 
            null, null, null, $data['contactGuid'] ?? null, $data['fileGuid'] ?? null, 
            date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')
        ]);

        $response->getBody()->write(json_encode(['message' => 'Purchase voucher created successfully']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Radera en inköpsfaktura
    $app->delete('/v1/{organizationId}/vouchers/purchase/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $voucherGuid = $args['guid'];

        $stmt = $pdo->prepare("DELETE FROM purchase_voucher WHERE guid = ?");
        $stmt->execute([$voucherGuid]);

        $response->getBody()->write(json_encode(['message' => 'Purchase voucher deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });
};
