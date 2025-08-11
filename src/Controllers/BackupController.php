<?php
namespace Invoicemate\Controllers;

use PDO;
use DateTime;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class BackupController
{
    private PDO $pdo;
    private array $settings;

    public function __construct(PDO $pdo, array $settings)
    {
        $this->pdo = $pdo;
        $this->settings = $settings;
    }

    /**
     * Run backup (admin only). Creates DB and uploads backups and returns filenames.
     */
    public function runBackup(Request $request, Response $response, array $args): Response
    {
        $backupsDir = __DIR__ . '/../../backups';
        if (!is_dir($backupsDir)) {
            mkdir($backupsDir, 0770, true);
        }
        $now = new DateTime();
        $dateStr = $now->format('Ymd_His');
        // DB backup
        $dbFile = $this->settings['db']['path'] ?? __DIR__ . '/../../data/database.sqlite';
        $backupDbName = 'db_' . $dateStr . '.sqlite';
        $backupDbPath = $backupsDir . '/' . $backupDbName;
        // Use sqlite3 VACUUM INTO if possible
        try {
            $this->pdo->exec("VACUUM INTO '$backupDbPath'");
        } catch (\Throwable $e) {
            // fallback copy
            copy($dbFile, $backupDbPath);
        }
        // Uploads backup
        $uploadsDir = $this->settings['uploads_dir'] ?? __DIR__ . '/../../uploads';
        $backupUploadsName = 'uploads_' . $dateStr . '.zip';
        $backupUploadsPath = $backupsDir . '/' . $backupUploadsName;
        $cmd = sprintf('cd %s && zip -r %s .', escapeshellarg($uploadsDir), escapeshellarg($backupUploadsPath));
        shell_exec($cmd);
        // Retention: keep only 10 of each
        $this->prune($backupsDir, 'db_*.sqlite', 10);
        $this->prune($backupsDir, 'uploads_*.zip', 10);
        return $this->json($response, ['db_backup' => $backupDbName, 'uploads_backup' => $backupUploadsName]);
    }

    private function prune(string $dir, string $pattern, int $keep): void
    {
        $files = glob($dir . '/' . $pattern);
        rsort($files);
        $excess = array_slice($files, $keep);
        foreach ($excess as $file) {
            @unlink($file);
        }
    }

    private function json(Response $response, $data, int $status = 200): Response
    {
        $response->getBody()->rewind();
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}