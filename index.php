<?php
require __DIR__ . '/vendor/autoload.php';

use Slim\App;

// Load environment variables from .env if available
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = $value;
    }
}

// Build container
$containerBuilder = require __DIR__ . '/dependencies.php';
$container = $containerBuilder();

// Create Slim app
$app = new App($container);

// Register routes
$routes = require __DIR__ . '/routes.php';
$routes($app);

// Run application
$app->run();