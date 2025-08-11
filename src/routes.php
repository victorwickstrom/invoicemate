<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

/**
 * Central route registration.
 *
 * This function wires up all route groups used by the application.  It
 * ensures each module is loaded from its dedicated file which returns
 * a callable accepting the Slim\App instance.  Additional modules such
 * as the webhook routes introduced in the 2025 refactor are registered
 * here.  Finally a global JSON error middleware is added.
 */
return function (App $app) {
    $container = $app->getContainer();

    // Database test endpoint
    $app->get('/test-db', function (Request $request, Response $response) use ($container) {
        if (!$container->has('db')) {
            throw new \RuntimeException("Database connection not found");
        }
        $pdo = $container->get('db');
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($tables));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Load route modules.  Each require returns a callable which
    // registers its routes on the provided app instance.
    $invoiceRoutes = require __DIR__ . '/invoiceRoutes.php';
    $invoiceRoutes($app);
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
    // Our updated organisation routes with /v1 prefix
    $organizationsRoutes = require __DIR__ . '/organizationsRoutes.php';
    $organizationsRoutes($app);
    $manualVouchersRoutes = require __DIR__ . '/manualVouchersRoutes.php';
    $manualVouchersRoutes($app);
    $productsRoutes = require __DIR__ . '/productsRoutes.php';
    $productsRoutes($app);
    $purchaseCreditNotesRoutes = require __DIR__ . '/purchaseCreditNotesRoutes.php';
    $purchaseCreditNotesRoutes($app);
    $purchaseVoucherCreditPaymentsRoutes = require __DIR__ . '/purchaseVoucherCreditPaymentsRoutes.php';
    $purchaseVoucherCreditPaymentsRoutes($app);
    $purchaseVouchersRoutes = require __DIR__ . '/purchaseVouchersRoutes.php';
    $purchaseVouchersRoutes($app);
    $remindersRoutes = require __DIR__ . '/remindersRoutes.php';
    $remindersRoutes($app);
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
    $saftImportRoutes = require __DIR__ . '/saftImportRoutes.php';
    $saftImportRoutes($app);
    $saftExportRoutes = require __DIR__ . '/saftExportRoutes.php';
    $saftExportRoutes($app);
    // New webhook routes
    $webhookRoutes = require __DIR__ . '/webhookRoutes.php';
    $webhookRoutes($app);
    // Global JSON error handler
    $app->add($container->get(\App\Middleware\JsonErrorMiddleware::class));
};