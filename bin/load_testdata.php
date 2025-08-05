#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Determine SQLite file location
$dbPath = __DIR__ . '/../database.sqlite';
$dsn    = 'sqlite:' . $dbPath;

try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    fwrite(STDERR, "Failed to connect to SQLite database: {$e->getMessage()}\n");
    exit(1);
}

$sqlFile = __DIR__ . '/../sql.txt';
if (!file_exists($sqlFile)) {
    fwrite(STDERR, "SQL file not found: {$sqlFile}\n");
    exit(1);
}

$sql = file_get_contents($sqlFile);
try {
    $pdo->exec($sql);
    echo "Test data imported successfully\n";
} catch (PDOException $e) {
    fwrite(STDERR, "Failed to import test data: {$e->getMessage()}\n");
    exit(1);
}