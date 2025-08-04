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
};
