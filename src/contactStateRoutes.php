<?php
/**
 * Routes for retrieving and exporting the state of account for a contact.
 *
 * The original implementation returned a bare list of ledger entries. This
 * version adds support for PDF export and real email sending using
 * PHPMailer (if available) as well as improved summary information. The
 * calculations return income, expenses and a net balance. Entry type
 * 'Ultimo' is considered an opening balance and is included unless
 * explicitly filtered out via the hideClosed flag.
 */

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Attempt to load FPDF and PHPMailer if they are installed via Composer.
// If they are unavailable the PDF and email functionality will gracefully
// fall back.
if (!class_exists('FPDF')) {
    // @phpstan-ignore-next-line
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }
}

return function ($app) {
    $container = $app->getContainer();

    /**
     * Fetch ledger entries for a given contact and optional date range.
     * Returns an associative array with keys entries, income, expenses
     * and balance.
     *
     * @param \PDO $pdo
     * @param string $organizationId
     * @param string $contactGuid
     * @param string|null $fromDate
     * @param string|null $toDate
     * @param bool $hideClosed
     * @return array
     */
    $getStateOfAccount = function (\PDO $pdo, string $organizationId, string $contactGuid, ?string $fromDate, ?string $toDate, bool $hideClosed): array {
        $sql = "SELECT * FROM entries WHERE organization_id = :org AND contact_guid = :guid";
        if ($fromDate) {
            $sql .= " AND entry_date >= :from";
        }
        if ($toDate) {
            $sql .= " AND entry_date <= :to";
        }
        if ($hideClosed) {
            // 'Ultimo' represents closing entries; we exclude them when hiding closed periods
            $sql .= " AND entry_type != 'Ultimo'";
        }
        $sql .= " ORDER BY entry_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':org', $organizationId);
        $stmt->bindValue(':guid', $contactGuid);
        if ($fromDate) {
            $stmt->bindValue(':from', $fromDate);
        }
        if ($toDate) {
            $stmt->bindValue(':to', $toDate);
        }
        $stmt->execute();
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $income = 0.0;
        $expenses = 0.0;
        foreach ($entries as $entry) {
            $amount = (float) $entry['amount'];
            if ($amount > 0) {
                $income += $amount;
            } else {
                $expenses += abs($amount);
            }
        }
        $balance = $income - $expenses;
        return ['entries' => $entries, 'income' => $income, 'expenses' => $expenses, 'balance' => $balance];
    };

    /**
     * Generate a simple PDF summarising the account state using FPDF. The
     * function returns the PDF document as a binary string. If FPDF is
     * unavailable the function returns null.
     *
     * @param string $contactName
     * @param array $stateData
     * @param string|null $fromDate
     * @param string|null $toDate
     * @return string|null
     */
    $generateStatePdf = function (string $contactName, array $stateData, ?string $fromDate, ?string $toDate): ?string {
        if (!class_exists('FPDF')) {
            return null;
        }
        $pdf = new \FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, 'Kontoudtog', 0, 1);
        $pdf->SetFont('Arial', '', 12);
        $period = '';
        if ($fromDate) {
            $period .= 'Fra ' . $fromDate;
        }
        if ($toDate) {
            $period .= ($period ? ' til ' : 'Til ') . $toDate;
        }
        if ($period) {
            $pdf->Cell(0, 8, $period, 0, 1);
        }
        $pdf->Cell(0, 8, 'Kunde: ' . $contactName, 0, 1);
        $pdf->Ln(5);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(60, 8, 'Dato', 1);
        $pdf->Cell(60, 8, 'Beskrivelse', 1);
        $pdf->Cell(40, 8, 'Beløb', 1);
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 12);
        foreach ($stateData['entries'] as $entry) {
            $pdf->Cell(60, 8, $entry['entry_date'], 1);
            $pdf->Cell(60, 8, mb_substr($entry['description'] ?? '', 0, 20), 1);
            $amount = number_format((float) $entry['amount'], 2, ',', '.');
            $pdf->Cell(40, 8, $amount, 1, 1, 'R');
        }
        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(120, 8, 'Indtægter:', 1);
        $pdf->Cell(40, 8, number_format($stateData['income'], 2, ',', '.'), 1, 1, 'R');
        $pdf->Cell(120, 8, 'Udgifter:', 1);
        $pdf->Cell(40, 8, number_format($stateData['expenses'], 2, ',', '.'), 1, 1, 'R');
        $pdf->Cell(120, 8, 'Balance:', 1);
        $pdf->Cell(40, 8, number_format($stateData['balance'], 2, ',', '.'), 1, 1, 'R');
        return $pdf->Output('', 'S');
    };

    /**
     * Send an email with optional PDF attachment using PHPMailer if
     * available. Falls back to PHP's mail() function when PHPMailer is
     * absent or fails. Returns true on success, false on failure.
     *
     * @param string $from
     * @param string $to
     * @param string $subject
     * @param string $body
     * @param bool $ccToSender
     * @param string|null $pdfData
     * @param string|null $pdfFilename
     * @return bool
     */
    $sendEmailWithAttachment = function (string $from, string $to, string $subject, string $body, bool $ccToSender, ?string $pdfData, ?string $pdfFilename) {
        // Attempt PHPMailer
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                // Server settings can be configured via environment or config
                $mail->setFrom($from);
                $mail->addAddress($to);
                if ($ccToSender) {
                    $mail->addCC($from);
                }
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->isHTML(false);
                if ($pdfData && $pdfFilename) {
                    $mail->addStringAttachment($pdfData, $pdfFilename, 'base64', 'application/pdf');
                }
                return $mail->send();
            } catch (\Throwable $e) {
                // fall back below
            }
        }
        // Fallback: build a simple multipart message and use mail()
        $boundary = md5((string) rand());
        $headers = "From: $from\r\n";
        if ($ccToSender) {
            $headers .= "Cc: $from\r\n";
        }
        $headers .= "MIME-Version: 1.0\r\n";
        if ($pdfData && $pdfFilename) {
            $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
            $message = "--$boundary\r\n";
            $message .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
            $message .= $body . "\r\n";
            $message .= "--$boundary\r\n";
            $message .= "Content-Type: application/pdf; name=\"$pdfFilename\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "Content-Disposition: attachment; filename=\"$pdfFilename\"\r\n\r\n";
            $message .= chunk_split(base64_encode($pdfData)) . "\r\n";
            $message .= "--$boundary--";
        } else {
            $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
            $message = $body;
        }
        return mail($to, $subject, $message, $headers);
    };

    // JSON: Get a contact's state of account
    $app->get('/{organizationId}/state-of-account/{guid}', function (Request $request, Response $response, array $args) use ($container, $getStateOfAccount) {
        $pdo = $container->get(PDO::class);
        $organizationId = $args['organizationId'];
        $contactGuid = $args['guid'];
        $queryParams = $request->getQueryParams();
        $fromDate = $queryParams['from'] ?? null;
        $toDate = $queryParams['to'] ?? null;
        $hideClosed = isset($queryParams['hideClosed']) && $queryParams['hideClosed'] === 'true';
        $state = $getStateOfAccount($pdo, $organizationId, $contactGuid, $fromDate, $toDate, $hideClosed);
        $response->getBody()->write(json_encode([
            'contactGuid' => $contactGuid,
            'income' => $state['income'],
            'expenses' => $state['expenses'],
            'balance' => $state['balance'],
            'entries' => $state['entries'],
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // PDF: Get a contact's state of account as PDF
    $app->get('/{organizationId}/state-of-account/{guid}/pdf', function (Request $request, Response $response, array $args) use ($container, $getStateOfAccount, $generateStatePdf) {
        $pdo = $container->get(PDO::class);
        $organizationId = $args['organizationId'];
        $contactGuid = $args['guid'];
        $queryParams = $request->getQueryParams();
        $fromDate = $queryParams['from'] ?? null;
        $toDate = $queryParams['to'] ?? null;
        $hideClosed = isset($queryParams['hideClosed']) && $queryParams['hideClosed'] === 'true';
        // Fetch contact name
        $stmt = $pdo->prepare("SELECT name FROM contacts WHERE organization_id = :orgId AND contact_guid = :guid");
        $stmt->execute([':orgId' => $organizationId, ':guid' => $contactGuid]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contact) {
            $response->getBody()->write(json_encode(['error' => 'Contact not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $state = $getStateOfAccount($pdo, $organizationId, $contactGuid, $fromDate, $toDate, $hideClosed);
        $pdfData = $generateStatePdf($contact['name'], $state, $fromDate, $toDate);
        if ($pdfData === null) {
            $response->getBody()->write(json_encode(['error' => 'PDF generation is not available']));
            return $response->withStatus(501)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write($pdfData);
        return $response->withHeader('Content-Type', 'application/pdf');
    });

    // Email: Send a contact's state of account via e-mail
    $app->post('/{organizationId}/state-of-account/{guid}/email', function (Request $request, Response $response, array $args) use ($container, $getStateOfAccount, $generateStatePdf, $sendEmailWithAttachment) {
        $pdo = $container->get(PDO::class);
        $organizationId = $args['organizationId'];
        $contactGuid = $args['guid'];
        $data = $request->getParsedBody();
        $queryParams = $request->getQueryParams();
        $fromDate = $queryParams['from'] ?? null;
        $toDate = $queryParams['to'] ?? null;
        $hideClosed = isset($queryParams['hideClosed']) && $queryParams['hideClosed'] === 'true';
        // Validate required fields
        if (!isset($data['sender'], $data['subject'], $data['message'])) {
            $response->getBody()->write(json_encode(['error' => 'Missing sender, subject or message']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        // Fetch contact info
        $stmt = $pdo->prepare("SELECT email, name FROM contacts WHERE organization_id = :orgId AND contact_guid = :guid");
        $stmt->execute([':orgId' => $organizationId, ':guid' => $contactGuid]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contact) {
            $response->getBody()->write(json_encode(['error' => 'Contact not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        // Determine receiver address (explicit or fallback to contact email)
        $receiverEmail = $data['receiver'] ?? $contact['email'];
        $senderEmail = $data['sender'];
        $subject = $data['subject'];
        $messageBody = $data['message'];
        $ccToSender = isset($data['ccToSender']) ? (bool) $data['ccToSender'] : false;
        // Generate PDF if requested via attachPdf flag (defaults to true)
        $attachPdf = isset($data['attachPdf']) ? (bool) $data['attachPdf'] : true;
        $pdfData = null;
        $pdfFilename = null;
        if ($attachPdf) {
            $state = $getStateOfAccount($pdo, $organizationId, $contactGuid, $fromDate, $toDate, $hideClosed);
            $pdfData = $generateStatePdf($contact['name'], $state, $fromDate, $toDate);
            if ($pdfData !== null) {
                $pdfFilename = 'state-of-account-' . $contactGuid . '.pdf';
            }
        }
        $sent = $sendEmailWithAttachment($senderEmail, $receiverEmail, $subject, $messageBody, $ccToSender, $pdfData, $pdfFilename);
        if (!$sent) {
            $response->getBody()->write(json_encode(['error' => 'Failed to send email']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode(['message' => 'Email sent successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });
};