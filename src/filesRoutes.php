<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Ladda upp en fil
    $app->post('/{organizationId}/files', function (Request $request, Response $response, array $args) use ($container) {
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

        // Kontrollera filstorlek (max 6 MB)
        if ($file->getSize() > 6 * 1024 * 1024) {
            return $response->withStatus(400)->withJson(["error" => "File size exceeds 6 MB"]);
        }

        $fileGuid = uniqid();
        $fileName = pathinfo($file->getClientFilename(), PATHINFO_FILENAME);
        $fileExtension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $fileSize = $file->getSize();
        $uploadedAt = date("Y-m-d\TH:i:s\Z");

        // Spara filen lokalt
        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $filePath = $uploadDir . $fileGuid . "." . $fileExtension;
        $file->moveTo($filePath);

        // Spara metadata i databasen
        $stmt = $pdo->prepare("
            INSERT INTO files (file_guid, organization_id, name, extension, size, uploaded_at, file_status, file_location)
            VALUES (:fileGuid, :organizationId, :name, :extension, :size, :uploadedAt, 'Unused', :fileLocation)
        ");
        $stmt->execute([
            ':fileGuid' => $fileGuid,
            ':organizationId' => $organizationId,
            ':name' => $fileName,
            ':extension' => $fileExtension,
            ':size' => $fileSize,
            ':uploadedAt' => $uploadedAt,
            ':fileLocation' => $filePath
        ]);

        return $response->withStatus(200)->withJson(["fileGuid" => $fileGuid]);
    });

    // Lista alla filer
    $app->get('/{organizationId}/files', function (Request $request, Response $response, array $args) use ($container) {
        if (!$container->has('db')) {
            throw new \RuntimeException("Database connection not found");
        }

        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];

        $queryParams = $request->getQueryParams();
        $extensions = isset($queryParams['extensions']) ? explode(',', $queryParams['extensions']) : [];
        $uploadedBefore = $queryParams['uploadedBefore'] ?? null;
        $uploadedAfter = $queryParams['uploadedAfter'] ?? null;
        $fileStatus = $queryParams['fileStatus'] ?? 'All';
        $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 0;
        $pageSize = isset($queryParams['pageSize']) ? (int)$queryParams['pageSize'] : 1000;

        // Bygg SQL-query
        $sql = "SELECT * FROM files WHERE organization_id = :organizationId AND deleted_at IS NULL";
        if (!empty($extensions)) {
            $placeholders = implode(',', array_fill(0, count($extensions), '?'));
            $sql .= " AND extension IN ($placeholders)";
        }
        if ($uploadedBefore) {
            $sql .= " AND uploaded_at <= :uploadedBefore";
        }
        if ($uploadedAfter) {
            $sql .= " AND uploaded_at >= :uploadedAfter";
        }
        if ($fileStatus !== 'All') {
            $sql .= " AND file_status = :fileStatus";
        }
        $sql .= " ORDER BY uploaded_at DESC LIMIT :offset, :limit";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':organizationId', $organizationId, PDO::PARAM_STR);
        if (!empty($extensions)) {
            foreach ($extensions as $index => $ext) {
                $stmt->bindValue($index + 1, $ext, PDO::PARAM_STR);
            }
        }
        if ($uploadedBefore) {
            $stmt->bindValue(':uploadedBefore', $uploadedBefore, PDO::PARAM_STR);
        }
        if ($uploadedAfter) {
            $stmt->bindValue(':uploadedAfter', $uploadedAfter, PDO::PARAM_STR);
        }
        if ($fileStatus !== 'All') {
            $stmt->bindValue(':fileStatus', $fileStatus, PDO::PARAM_STR);
        }
        $stmt->bindValue(':offset', $page * $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->execute();
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($files));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Ladda ner en fil
    $app->get('/{organizationId}/files/{fileGuid}', function (Request $request, Response $response, array $args) use ($container) {
        if (!$container->has('db')) {
            throw new \RuntimeException("Database connection not found");
        }

        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $fileGuid = $args['fileGuid'];

        $stmt = $pdo->prepare("SELECT * FROM files WHERE organization_id = :organizationId AND file_guid = :fileGuid AND deleted_at IS NULL");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':fileGuid', $fileGuid, PDO::PARAM_STR);
        $stmt->execute();
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            return $response->withStatus(404)->withJson(["error" => "File not found"]);
        }

        $filePath = $file['file_location'];
        if (!file_exists($filePath)) {
            return $response->withStatus(404)->withJson(["error" => "File not found on server"]);
        }

        $response = $response->withHeader('Content-Type', mime_content_type($filePath))
                             ->withHeader('Content-Disposition', 'attachment; filename="' . $file['name'] . '.' . $file['extension'] . '"')
                             ->withHeader('Content-Length', filesize($filePath));
        readfile($filePath);
        return $response;
    });
};
