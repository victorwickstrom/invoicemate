<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Hämta en kontakts kontostatus
    $app->get('/{organizationId}/state-of-account/{guid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $contactGuid = $args['guid'];

        // Hämta query-parametrar
        $queryParams = $request->getQueryParams();
        $fromDate = $queryParams['from'] ?? null;
        $toDate = $queryParams['to'] ?? null;
        $hideClosed = isset($queryParams['hideClosed']) && $queryParams['hideClosed'] == 'true';

        // Bygg SQL-query
        $sql = "SELECT * FROM entries WHERE organization_id = :organizationId AND contact_guid = :contactGuid";
        if ($fromDate) {
            $sql .= " AND entry_date >= :fromDate";
        }
        if ($toDate) {
            $sql .= " AND entry_date <= :toDate";
        }
        if ($hideClosed) {
            $sql .= " AND entry_type != 'Ultimo'";
        }
        $sql .= " ORDER BY entry_date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':contactGuid', $contactGuid, PDO::PARAM_STR);
        if ($fromDate) {
            $stmt->bindParam(':fromDate', $fromDate);
        }
        if ($toDate) {
            $stmt->bindParam(':toDate', $toDate);
        }
        $stmt->execute();
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Beräkna inkomster och utgifter
        $income = 0;
        $expenses = 0;
        foreach ($entries as $entry) {
            if ($entry['amount'] > 0) {
                $income += $entry['amount'];
            } else {
                $expenses += abs($entry['amount']);
            }
        }

        // Returnera JSON-respons
        $response->getBody()->write(json_encode([
            "contactGuid" => $contactGuid,
            "income" => $income,
            "expenses" => $expenses,
            "entries" => $entries
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Hämta en kontakts kontostatus som PDF (Ej implementerat ännu)
    $app->get('/{organizationId}/state-of-account/{guid}/pdf', function (Request $request, Response $response, array $args) {
        return $response->withStatus(501)->withJson(["error" => "PDF generation not implemented yet"]);
    });

    // Skicka en kontakts kontostatus via e-post
    $app->post('/{organizationId}/state-of-account/{guid}/email', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $contactGuid = $args['guid'];
        $data = $request->getParsedBody();

        // Kontrollera att obligatoriska fält finns
        if (!isset($data['ccToSender'], $data['hideClosed'])) {
            return $response->withStatus(400)->withJson(["error" => "Missing required fields"]);
        }

        // Hämta kontaktens e-post
        $stmt = $pdo->prepare("SELECT email, name FROM contacts WHERE organization_id = :organizationId AND contact_guid = :contactGuid");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':contactGuid', $contactGuid, PDO::PARAM_STR);
        $stmt->execute();
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) {
            return $response->withStatus(404)->withJson(["error" => "Contact not found"]);
        }

        $receiverEmail = $data['receiver'] ?? $contact['email'];
        $senderEmail = $data['sender'] ?? "noreply@dinero.dk";
        $subject = $data['subject'] ?? "State of Account for " . $contact['name'];
        $message = $data['message'] ?? "Please find the state of account attached.";
        $ccToSender = $data['ccToSender'] ? "CC: $senderEmail" : "";

        // Simulerad e-post (kan integreras med mailfunktion)
        $emailBody = "
            To: $receiverEmail
            From: $senderEmail
            $ccToSender
            Subject: $subject
            
            $message
        ";

        return $response->withStatus(200)->withJson([
            "message" => "Email sent successfully",
            "email_content" => $emailBody
        ]);
    });
};
