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
        $container->set(\App\Services\AuthService::class, function () {
            return new \App\Services\AuthService();
        });

        // InvoiceService depends on PDO
        $container->set(\App\Services\InvoiceService::class, function () use ($container) {
            return new \App\Services\InvoiceService($container->get('db'));
        });

        // Validator for invoices
        $container->set(\App\Validators\InvoiceValidator::class, function () {
            return new \App\Validators\InvoiceValidator();
        });

        // JWT authentication middleware.  We register it as a service so it can
        // be easily injected into route groups.  Aliasing under the key
        // 'auth' allows backwards compatibility with the previous AuthMiddleware.
        $container->set('auth', function () use ($container) {
            $authService = $container->get(\App\Services\AuthService::class);
            return new \App\Middleware\JwtAuthMiddleware($authService);
        });

        // Global JSON error middleware
        $container->set(\App\Middleware\JsonErrorMiddleware::class, function () {
            return new \App\Middleware\JsonErrorMiddleware();
        });
};
