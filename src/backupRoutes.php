<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Psr\Container\ContainerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Backup routes for admin operations.
 *
 * Provides endpoints to backup the SQLite database and the uploads directory.
 */
return function (App $app) {
    $container = $app->getContainer();

    $app->group('/v1/{organizationId}/admin/backup', function (RouteCollectorProxy $group) use ($container) {
        // Backup database
        $group->post('/db', function (Request $request, Response $response, array $args) use ($container) {
            $pdo = $container->get('db');
            $organizationId = $args['organizationId'];

            // Role-based access control: only admin may run backups
            $user = $request->getAttribute('user');
            $roles = $user['roles'] ?? [];
            if (!in_array('admin', $roles)) {
                $response->getBody()->write(json_encode(['error' => 'Forbidden: insufficient role']));
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
            }

            // Create backups directory if not exists
            $backupDir = __DIR__ . '/../backups';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0777, true);
            }

            // Determine source database file path (assume database.sqlite in project root)
            $dbPath = $container->get('settings')['db']['database_path'] ?? null;
            if (!$dbPath) {
                // fallback: assume database.sqlite in parent dir
                $dbPath = __DIR__ . '/../database.sqlite';
            }
            if (!file_exists($dbPath)) {
                $response->getBody()->write(json_encode(['error' => 'Database file not found']));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            $timestamp = date('Ymd_His');
            $backupFile = $backupDir . "/database_{$timestamp}.sqlite";
            // Use SQLite VACUUM INTO to create a consistent backup
            try {
                $pdo->exec("VACUUM INTO '{$backupFile}'");
            } catch (\Throwable $e) {
                // Fallback to file copy if VACUUM INTO not supported
                if (!copy($dbPath, $backupFile)) {
                    $response->getBody()->write(json_encode(['error' => 'Failed to backup database', 'details' => $e->getMessage()]));
                    return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
                }
            }

            // Keep only last 5 backups
            $backups = glob($backupDir . '/database_*.sqlite');
            rsort($backups);
            if (count($backups) > 5) {
                $oldBackups = array_slice($backups, 5);
                foreach ($oldBackups as $old) {
                    @unlink($old);
                }
            }

            $response->getBody()->write(json_encode(['status' => 'success', 'backupFile' => basename($backupFile)]));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // Backup uploads directory
        $group->post('/uploads', function (Request $request, Response $response, array $args) use ($container) {
            $organizationId = $args['organizationId'];
            // Role-based access control: only admin may run backups
            $user = $request->getAttribute('user');
            $roles = $user['roles'] ?? [];
            if (!in_array('admin', $roles)) {
                $response->getBody()->write(json_encode(['error' => 'Forbidden: insufficient role']));
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
            }

            $uploadsDir = __DIR__ . '/../uploads';
            if (!is_dir($uploadsDir)) {
                $response->getBody()->write(json_encode(['error' => 'Uploads directory not found']));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            $backupDir = __DIR__ . '/../backups';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0777, true);
            }

            $timestamp = date('Ymd_His');
            $zipFile = $backupDir . "/uploads_{$timestamp}.zip";

            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
                $response->getBody()->write(json_encode(['error' => 'Could not create zip archive']));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }
            // Recursively add files to zip
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploadsDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($uploadsDir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
            $zip->close();

            // Keep last 3 uploads backups
            $uploadBackups = glob($backupDir . '/uploads_*.zip');
            rsort($uploadBackups);
            if (count($uploadBackups) > 3) {
                $old = array_slice($uploadBackups, 3);
                foreach ($old as $del) {
                    @unlink($del);
                }
            }

            $response->getBody()->write(json_encode(['status' => 'success', 'backupFile' => basename($zipFile)]));
            return $response->withHeader('Content-Type', 'application/json');
        });
    });
};