<?php
namespace Invoicemate\Controllers;

use Invoicemate\Uploads\UploadService;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class InvoiceController
{
    private PDO $pdo;
    private UploadService $uploads;

    public function __construct(PDO $pdo, UploadService $uploads)
    {
        $this->pdo = $pdo;
        $this->uploads = $uploads;
    }

    /**
     * Download invoice PDF as a decrypted stream.
     */
    public function downloadPdf(Request $request, Response $response, array $args): Response
    {
        $orgId = (int)$args['organizationId'];
        $invoiceId = (int)$args['id'];
        $stmt = $this->pdo->prepare('SELECT pdf_id FROM invoice WHERE id = :id AND organization_id = :org');
        $stmt->execute([':id' => $invoiceId, ':org' => $orgId]);
        $pdfId = $stmt->fetchColumn();
        if (!$pdfId) {
            return $this->json($response, ['error' => 'PDF not found'], 404);
        }
        try {
            $stream = $this->uploads->streamDecrypted($pdfId);
            $body = $response->getBody();
            while (!feof($stream)) {
                $body->write(fread($stream, 8192));
            }
            fclose($stream);
            return $response->withHeader('Content-Type', 'application/pdf');
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => 'Failed to retrieve PDF'], 500);
        }
    }

    private function json(Response $response, $data, int $status = 200): Response
    {
        $response->getBody()->rewind();
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}