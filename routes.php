<?php
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Invoicemate\Controllers\AuthController;
use Invoicemate\Controllers\ReportController;
use Invoicemate\Controllers\SAFTController;
use Invoicemate\Controllers\BackupController;
use Invoicemate\Controllers\InvoiceController;
use Invoicemate\Middleware\AuthMiddleware;
use Invoicemate\Middleware\OrgGuardMiddleware;
use Invoicemate\Middleware\RoleMiddleware;

return function (App $app) {
    $container = $app->getContainer();
    // Middleware instances
    $auth = new AuthMiddleware(
        $container->get(Invoicemate\Utils\JWT::class),
        $container->get(Psr\Http\Message\ResponseFactoryInterface::class)
    );
    $orgGuard = new OrgGuardMiddleware(
        $container->get(Psr\Http\Message\ResponseFactoryInterface::class)
    );
    // Admin role middleware
    $roleAdmin = new RoleMiddleware(['admin'], $container->get(Psr\Http\Message\ResponseFactoryInterface::class));
    $roleAccountant = new RoleMiddleware(['admin','accountant'], $container->get(Psr\Http\Message\ResponseFactoryInterface::class));

    // Controllers
    $authCtrl = new AuthController($container->get(PDO::class), $container->get(Invoicemate\Utils\JWT::class));
    $reportCtrl = new ReportController($container->get(PDO::class));
    $saftCtrl = new SAFTController($container->get(PDO::class));
    $backupCtrl = new BackupController($container->get(PDO::class), $container->get('settings'));
    $invoiceCtrl = new InvoiceController($container->get(PDO::class), $container->get(Invoicemate\Uploads\UploadService::class));

    // Authentication routes
    $app->post('/v1/auth/login', [$authCtrl, 'login']);
    $app->get('/v1/me', $auth, function ($request, $response) use ($authCtrl) {
        return $authCtrl->me($request, $response);
    });

    // Grouped routes per organization
    $app->group('/v1/{organizationId}', function (RouteCollectorProxy $group) use ($reportCtrl, $saftCtrl, $backupCtrl, $invoiceCtrl, $roleAdmin, $roleAccountant) {
        // VAT report
        $group->get('/reports/vat', function ($request, $response, $args) use ($reportCtrl) {
            return $reportCtrl->vatReport($request, $response, $args);
        });
        // SAF-T export
        $group->get('/saft/export', function ($request, $response, $args) use ($saftCtrl) {
            return $saftCtrl->export($request, $response, $args);
        });
        // Backup admin-only
        $group->post('/admin/backup', function ($request, $response, $args) use ($backupCtrl) {
            return $backupCtrl->runBackup($request, $response, $args);
        })->add($roleAdmin);
        // Invoice PDF
        $group->get('/invoices/{id}/pdf', function ($request, $response, $args) use ($invoiceCtrl) {
            return $invoiceCtrl->downloadPdf($request, $response, $args);
        });
    })->add($orgGuard)->add($auth);

    // Load additional invoice routes if present
    if (file_exists(__DIR__ . '/invoiceRoutes.php')) {
        (require __DIR__ . '/invoiceRoutes.php')($app);
    }
};