<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer(); // Korrekt sätt att hämta containern i Slim v4

    $app->get('/test-db', function (Request $request, Response $response) use ($container) {
        if (!$container->has('db')) {
            throw new \RuntimeException("Database connection not found");
        }

        $pdo = $container->get('db'); // Hämta PDO från DI-container

        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($tables));
        return $response->withHeader('Content-Type', 'application/json');
    });

        // Ladda in faktura-rutterna via PSR-kompatibel definierare
        $invoiceRoutes = require __DIR__ . '/Routes/InvoiceRoutes.php';
        $invoiceRoutes($app);

     // Ladda in accounting-years-rutterna
    $accountingYearsRoutes = require __DIR__ . '/accountingYearsRoutes.php';
    $accountingYearsRoutes($app);

    $accountRoutes = require __DIR__ . '/accountRoutes.php';
    $accountRoutes($app);

    $attachmentRoutes = require __DIR__ . '/attachmentRoutes.php';
    $attachmentRoutes($app);

    $contactRoutes = require __DIR__ . '/contactRoutes.php';
    $contactRoutes($app);

    $contactStateRoutes = require __DIR__ . '/contactStateRoutes.php';
    $contactStateRoutes($app);

    $countriesRoutes = require __DIR__ . '/countriesRoutes.php';
    $countriesRoutes($app);

    $filesRoutes = require __DIR__ . '/filesRoutes.php';
    $filesRoutes($app);

    $ledgerItemsRoutes = require __DIR__ . '/ledgerItemsRoutes.php';
    $ledgerItemsRoutes($app);

    $organizationsRoutes = require __DIR__ . '/organizationsRoutes.php';
    $organizationsRoutes($app);

    $manualVouchersRoutes = require __DIR__ . '/manualVouchersRoutes.php';
    $manualVouchersRoutes($app);

    $productsRoutes = require __DIR__ . '/productsRoutes.php';
    $productsRoutes($app);

    // Ladda rutter för Purchase Credit Notes
    $purchaseCreditNotesRoutes = require __DIR__ . '/purchaseCreditNotesRoutes.php';
    $purchaseCreditNotesRoutes($app);

    // Ladda rutter för Purchase Voucher Credit Payments
    $purchaseVoucherCreditPaymentsRoutes = require __DIR__ . '/purchaseVoucherCreditPaymentsRoutes.php';
    $purchaseVoucherCreditPaymentsRoutes($app);

    // Ladda rutter för Purchase Vouchers
    $purchaseVouchersRoutes = require __DIR__ . '/purchaseVouchersRoutes.php';
    $purchaseVouchersRoutes($app);

    // Ladda rutter för Reminders
    $remindersRoutes = require __DIR__ . '/remindersRoutes.php';
    $remindersRoutes($app);

       // Ladda rutter för Reports
    $reportsRoutes = require __DIR__ . '/reportsRoutes.php';
    $reportsRoutes($app);

    $salesRoutes = require __DIR__ . '/salesRoutes.php';
    $salesRoutes($app);

    $salesCreditNotesRoutes = require __DIR__ . '/salesCreditNotesRoutes.php';
    $salesCreditNotesRoutes($app);

    $tradeOffersRoutes = require __DIR__ . '/tradeOffersRoutes.php';
    $tradeOffersRoutes($app);

    $vatRoutes = require __DIR__ . '/vatRoutes.php';
    $vatRoutes($app);

        // Lägg till global JSON-felhanterare
        $app->add($container->get(\App\Middleware\JsonErrorMiddleware::class));

};
