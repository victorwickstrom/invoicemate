<?php
declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Skapa container
$container = new Container();
AppFactory::setContainer($container);
$app = AppFactory::create();

// Lägg till tjänster och routes
(require __DIR__ . '/../src/dependencies.php')($container);
(require __DIR__ . '/../src/routes.php')($app);

// Kör appen
$app->run();
