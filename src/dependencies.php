<?php
declare(strict_types=1);

use Psr\Container\ContainerInterface;
use DI\Container;
use Invoicemate\Services\WebhookService;

/**
 * Dependency definitions for the DI container.
 *
 * This factory registers services such as the database connection,
 * authentication service, JWT middleware and the webhook dispatcher.  It
 * replaces the previous slim-specific factory to provide a central place
 * where new services can be wired up as the application grows.
 */
return function (Container $container) {
    // Database connection
    $container->set('db', function () {
        $dsn = getenv('DATABASE_URL') ?: 'sqlite:/var/www/database.sqlite';
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    });

    // Authentication service with JWT support.  The secret is read from the
    // environment; see App\AuthService for default fallback behaviour.
    $container->set(\App\AuthService::class, function () {
        return new \App\AuthService();
    });

    // JWT authentication middleware.  Registered as the key 'auth' for
    // backwards compatibility with earlier code.
    $container->set('auth', function () use ($container) {
        $authService = $container->get(\App\AuthService::class);
        return new \App\Middleware\JwtAuthMiddleware($authService);
    });

    // Webhook service used to dispatch event callbacks to subscribers.  It
    // requires a PDO connection and optionally a PSR-3 logger (not yet
    // registered; null fallback will disable logging).
    $container->set(WebhookService::class, function () use ($container) {
        /** @var PDO $pdo */
        $pdo = $container->get('db');
        // If monolog is registered in the container it will be injected; otherwise null.
        $logger = null;
        if ($container->has('logger')) {
            $logger = $container->get('logger');
        }
        return new WebhookService($pdo, $logger);
    });

    // Global JSON error middleware
    $container->set(\App\Middleware\JsonErrorMiddleware::class, function () {
        return new \App\Middleware\JsonErrorMiddleware();
    });
};