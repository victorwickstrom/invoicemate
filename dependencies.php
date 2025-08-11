<?php

use DI\Container;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;

use Invoicemate\Utils\JWT;
use Invoicemate\Utils\Crypto;
use Invoicemate\Uploads\UploadService;
use Invoicemate\Accounting\VoucherService;

/**
 * Build and return a DI container.
 *
 * This container holds shared services such as database connection,
 * logging, cryptography utilities and domain services. The container
 * is used by Slim for dependency injection into route callbacks.
 */
return function (): Container {
    $container = new Container();

    // Configure application settings
    $container->set('settings', function () {
        return [
            'db' => [
                'path' => __DIR__ . '/data/database.sqlite',
            ],
            'uploads_dir' => $_ENV['UPLOADS_DIR'] ?? __DIR__ . '/uploads',
            'uploads_key' => $_ENV['UPLOADS_KEY'] ?? 'insecure-default-key',
            'jwt_secret' => $_ENV['JWT_SECRET'] ?? 'super-secret-key',
        ];
    });

    // PSR-7 Response factory
    $container->set(ResponseFactoryInterface::class, function () {
        return new ResponseFactory();
    });

    // Logger service
    $container->set(Logger::class, function () {
        $logger = new Logger('invoicemate');
        $logger->pushHandler(new StreamHandler(__DIR__ . '/logs/app.log'));
        return $logger;
    });

    // Database connection (PDO)
    $container->set(PDO::class, function (Container $c) {
        $settings = $c->get('settings');
        $path = $settings['db']['path'];
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    });

    // JWT utility
    $container->set(JWT::class, function (Container $c) {
        $secret = $c->get('settings')['jwt_secret'];
        return new JWT($secret);
    });

    // Crypto utility
    $container->set(Crypto::class, function (Container $c) {
        $key = $c->get('settings')['uploads_key'];
        return new Crypto($key);
    });

    // Upload service
    $container->set(UploadService::class, function (Container $c) {
        $uploadsDir = $c->get('settings')['uploads_dir'];
        $crypto = $c->get(Crypto::class);
        return new UploadService($uploadsDir, $crypto);
    });

    // Voucher service
    $container->set(VoucherService::class, function (Container $c) {
        $pdo = $c->get(PDO::class);
        return new VoucherService($pdo);
    });

    return $container;
};