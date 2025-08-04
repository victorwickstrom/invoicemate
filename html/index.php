<?php
declare(strict_types=1);

use Slim\Factory\AppFactory;
use DI\Container;

require __DIR__ . '/../vendor/autoload.php';

// Skapa en ny Dependency Injection-container
$container = new Container();

// Ladda in dependencies
(require __DIR__ . '/../src/dependencies.php')($container);

// Skapa Slim-app med containern
AppFactory::setContainer($container);
$app = AppFactory::create();

// Ladda in routes
(require __DIR__ . '/../src/routes.php')($app);

// Middleware (för felhantering)
$app->addErrorMiddleware(true, true, true);

// Starta applikationen
$app->run();
