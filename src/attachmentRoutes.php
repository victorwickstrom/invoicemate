<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

/**
 * Attachment routes.
 *
 * File uploads are encrypted using AES‑256‑CBC before being stored on disk.
 * Metadata about each file is saved in the `attachments` table. Files can
 * later be associated with documents such as invoices via a linking endpoint.
 * Access to encrypted file contents is provided via a download endpoint that
 * decrypts on the fly. The `FILE_ENCRYPTION_KEY` environment variable is used
 * as the encryption key; in development a static key may be used. When a file
 * is attached to a document its status is updated to "Used".
 */
return function (App $app) {
    $container = $app->getContainer();

    /**
     * Encrypt raw data using AES‑256‑CBC. Returns base64 encoded cipher text
     * with IV prefixed. If no key is available an exception is thrown.
     */
    $encryptData = function ($data) {
        $key = getenv('FILE_ENCRYPTION_KEY') ?: 'development_key_32_bytes_long_123456';
        $key = substr(hash('sha256', $key, true), 0, 32);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    };

    /**
     * Decrypt data that was encrypted with encryptData. Accepts base64 string and
     * returns raw bytes.
     */
    $decryptData = function ($b64) {
        $key = getenv('FILE_ENCRYPTION_KEY') ?: 'development_key_32_bytes_long_123456';
        $key = substr(hash('sha256', $key, true), 0, 32);
        $data = base64_decode($b64);
        $iv = substr($data, 0, 16);
        $cipher = substr($data, 16);
        return openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    };

    // List all attachments for an organization
    $app->get('/{organizationId}/attachments', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $stmt = $pdo->prepare("SELECT * FROM attachments WHERE organization_id = :orgId ORDER BY created_at DESC");
        $stmt->execute([':orgId' => $orgId]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($attachments));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Retrieve metadata for a specific attachment (does not include file bytes)
    $app->get('/{organizationId}/attachments/{fileGuid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $fileGuid = $args['fileGuid'];
        $stmt = $pdo->prepare("SELECT * FROM attachments WHERE organization_id = :orgId AND file_guid = :fileGuid");
        $stmt->execute([':orgId' => $orgId, ':fileGuid' => $fileGuid]);
        $attachment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attachment) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Attachment not found']));
        }
        $response->getBody()->write(json_encode($attachment));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Upload a file (encrypted) and store metadata
    $app->post('/{organizationId}/attachments', function (Request $request, Response $response, array $args) use ($container, $encryptData) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $uploadedFiles = $request->getUploadedFiles();
        if (empty($uploadedFiles['file'])) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'No file uploaded']));
        }
        $file = $uploadedFiles['file'];
        $fileGuid = uniqid('file_', true);
        $fileName = $file->getClientFilename();
        $documentGuid = $request->getParsedBody()['document_guid'] ?? null;
        $documentType = $request->getParsedBody()['document_type'] ?? null;
        $fileStatus = 'Unused';
        // Read file content and encrypt
        $content = $file->getStream()->getContents();
        $encrypted = $encryptData($content);
        // Save encrypted content to disk
        $uploadDir = __DIR__ . '/../uploads_encrypted/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $filePath = $uploadDir . $fileGuid;
        file_put_contents($filePath, $encrypted);
        // Insert metadata
        $stmt = $pdo->prepare("INSERT INTO attachments (organization_id, document_guid, file_guid, file_name, document_type, file_status, created_at) VALUES (:orgId, :docGuid, :fileGuid, :fileName, :documentType, :fileStatus, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':orgId' => $orgId,
            ':docGuid' => $documentGuid,
            ':fileGuid' => $fileGuid,
            ':fileName' => $fileName,
            ':documentType' => $documentType,
            ':fileStatus' => $fileStatus
        ]);
        $response->getBody()->write(json_encode(['message' => 'File uploaded successfully', 'fileGuid' => $fileGuid]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Download (decrypt) a file
    $app->get('/{organizationId}/attachments/{fileGuid}/download', function (Request $request, Response $response, array $args) use ($container, $decryptData) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $fileGuid = $args['fileGuid'];
        $stmt = $pdo->prepare("SELECT file_name FROM attachments WHERE organization_id = :orgId AND file_guid = :fileGuid");
        $stmt->execute([':orgId' => $orgId, ':fileGuid' => $fileGuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Attachment not found']));
        }
        $filePath = __DIR__ . '/../uploads_encrypted/' . $fileGuid;
        if (!file_exists($filePath)) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'File not found on disk']));
        }
        $encrypted = file_get_contents($filePath);
        $decrypted = $decryptData($encrypted);
        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, $decrypted);
        rewind($stream);
        return $response->withHeader('Content-Disposition', 'attachment; filename="' . $row['file_name'] . '"')
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withBody(new \Slim\Http\Stream($stream));
    });

    // Link an existing file to an invoice and mark as used
    $app->post('/{organizationId}/invoices/{invoiceGuid}/attachments', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $invoiceGuid = $args['invoiceGuid'];
        $data = $request->getParsedBody();
        $fileGuid = $data['file_guid'] ?? null;
        if (!$fileGuid) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'file_guid required']));
        }
        // Ensure file exists
        $stmt = $pdo->prepare("SELECT * FROM attachments WHERE organization_id = :orgId AND file_guid = :fileGuid");
        $stmt->execute([':orgId' => $orgId, ':fileGuid' => $fileGuid]);
        $fileRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fileRow) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'File not found']));
        }
        // Link file to invoice
        $stmtUpd = $pdo->prepare("UPDATE attachments SET document_guid = :docGuid, document_type = 'invoice', file_status = 'Used' WHERE organization_id = :orgId AND file_guid = :fileGuid");
        $stmtUpd->execute([':docGuid' => $invoiceGuid, ':orgId' => $orgId, ':fileGuid' => $fileGuid]);
        $response->getBody()->write(json_encode(['message' => 'File linked to invoice successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Delete an attachment (only if unused)
    $app->delete('/{organizationId}/attachments/{fileGuid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $fileGuid = $args['fileGuid'];
        $stmt = $pdo->prepare("SELECT file_status FROM attachments WHERE organization_id = :orgId AND file_guid = :fileGuid");
        $stmt->execute([':orgId' => $orgId, ':fileGuid' => $fileGuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Attachment not found']));
        }
        if ($row['file_status'] === 'Used') {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Cannot delete file that is marked as Used']));
        }
        // Remove file on disk
        $filePath = __DIR__ . '/../uploads_encrypted/' . $fileGuid;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        // Remove metadata
        $pdo->prepare("DELETE FROM attachments WHERE organization_id = :orgId AND file_guid = :fileGuid")->execute([':orgId' => $orgId, ':fileGuid' => $fileGuid]);
        $response->getBody()->write(json_encode(['message' => 'Attachment deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });
};