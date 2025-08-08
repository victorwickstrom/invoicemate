<?php
declare(strict_types=1);

use Psr\Container\ContainerInterface;
use DI\Container;

return function (Container $container) {
        // Databasanslutning
        $container->set('db', function () {
            $dsn = getenv('DATABASE_URL') ?: 'sqlite:/var/www/database.sqlite';
            $pdo = new PDO($dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        });

        // AuthService used by JwtAuthMiddleware
        $container->set(\App\AuthService::class, function () {
            return new \App\AuthService();
        });

        // Vi registrerar inte längre InvoiceService eller InvoiceValidator i containern
        // eftersom fakturalogiken ligger direkt i invoiceRoutes.php och inte i separata klasser.

        // JWT authentication middleware.  We register it as a service so it can
        // be easily injected into route groups.  Aliasing under the key
        // 'auth' allows backwards compatibility with the previous AuthMiddleware.
        $container->set('auth', function () use ($container) {
            $authService = $container->get(\App\AuthService::class);
            return new \App\Middleware\JwtAuthMiddleware($authService);
        });

        // Global JSON error middleware
        $container->set(\App\Middleware\JsonErrorMiddleware::class, function () {
            return new \App\Middleware\JsonErrorMiddleware();
        });
};
