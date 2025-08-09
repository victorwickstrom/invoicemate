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

// Attach JWT authentication middleware globally.  This ensures that
// all routes (except those explicitly exempted inside the middleware
// implementation) require a valid JWT.  It must be added after the
// routes have been registered so the middleware can access route
// arguments.
$app->add($container->get('auth'));

// Kör appen
$app->run();
