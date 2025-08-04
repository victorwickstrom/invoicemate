<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Hämta alla bilagor för en organisation
    $app->get('/{organizationId}/attachments', function (Request $request, Response $response, array $args) use ($container) {
        if (!$container->has('db')) {
            throw new \RuntimeException("Database connection not found");
        }

        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];

        $stmt = $pdo->prepare("SELECT * FROM attachments WHERE organization_id = :organizationId ORDER BY created_at DESC");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_INT);
        $stmt->execute();
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($attachments));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Hämta en specifik bilaga
    $app->get('/{organizationId}/attachments/{fileGuid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $fileGuid = $args['fileGuid'];

        $stmt = $pdo->prepare("SELECT * FROM attachments WHERE organization_id = :organizationId AND file_guid = :fileGuid");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_INT);
        $stmt->bindParam(':fileGuid', $fileGuid, PDO::PARAM_STR);
        $stmt->execute();
        $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$attachment) {
            return $response->withStatus(404)->withJson(["error" => "Attachment not found"]);
        }

        $response->getBody()->write(json_encode($attachment));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Ladda upp en ny bilaga
    $app->post('/{organizationId}/attachments', function (Request $request, Response $response, array $args) use ($container) {
        if (!$container->has('db')) {
            throw new \RuntimeException("Database connection not found");
        }

        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];

        $uploadedFiles = $request->getUploadedFiles();
        if (empty($uploadedFiles['file'])) {
            return $response->withStatus(400)->withJson(["error" => "No file uploaded"]);
        }

        $file = $uploadedFiles['file'];
        $fileGuid = uniqid();
        $fileName = $file->getClientFilename();
        $documentGuid = $request->getParsedBody()['document_guid'] ?? null;
        $documentType = $request->getParsedBody()['document_type'] ?? "general";

        // Spara filen
        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $filePath = $uploadDir . $fileGuid . "_" . $fileName;
        $file->moveTo($filePath);

        // Spara metadata i databasen
        $stmt = $pdo->prepare("
            INSERT INTO attachments (organization_id, document_guid, file_guid, file_name, document_type, created_at)
            VALUES (:organizationId, :documentGuid, :fileGuid, :fileName, :documentType, CURRENT_TIMESTAMP)
        ");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_INT);
        $stmt->bindParam(':documentGuid', $documentGuid, PDO::PARAM_STR);
        $stmt->bindParam(':fileGuid', $fileGuid, PDO::PARAM_STR);
        $stmt->bindParam(':fileName', $fileName, PDO::PARAM_STR);
        $stmt->bindParam(':documentType', $documentType, PDO::PARAM_STR);
        $stmt->execute();

        return $response->withStatus(201)->withJson(["message" => "File uploaded successfully", "fileGuid" => $fileGuid]);
    });

    // Radera en bilaga
    $app->delete('/{organizationId}/attachments/{fileGuid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $fileGuid = $args['fileGuid'];

        // Kontrollera om bilagan finns
        $stmt = $pdo->prepare("SELECT * FROM attachments WHERE organization_id = :organizationId AND file_guid = :fileGuid");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_INT);
        $stmt->bindParam(':fileGuid', $fileGuid, PDO::PARAM_STR);
        $stmt->execute();
        $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$attachment) {
            return $response->withStatus(404)->withJson(["error" => "Attachment not found"]);
        }

        // Radera filen från servern
        $filePath = __DIR__ . '/../uploads/' . $attachment['file_guid'] . "_" . $attachment['file_name'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Ta bort posten från databasen
        $stmt = $pdo->prepare("DELETE FROM attachments WHERE organization_id = :organizationId AND file_guid = :fileGuid");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_INT);
        $stmt->bindParam(':fileGuid', $fileGuid, PDO::PARAM_STR);
        $stmt->execute();

        return $response->withStatus(200)->withJson(["message" => "Attachment deleted successfully"]);
    });
};
