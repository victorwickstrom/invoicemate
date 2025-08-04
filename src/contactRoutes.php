<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Hämta alla kontakter för en organisation
    $app->get('/{organizationId}/contacts', function (Request $request, Response $response, array $args) use ($container) {
        if (!$container->has('db')) {
            throw new \RuntimeException("Database connection not found");
        }

        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];

        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE organization_id = :organizationId AND deleted_at IS NULL ORDER BY name ASC");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->execute();
        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($contacts));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Hämta en specifik kontakt
    $app->get('/{organizationId}/contacts/{contactGuid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $contactGuid = $args['contactGuid'];

        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE organization_id = :organizationId AND contact_guid = :contactGuid AND deleted_at IS NULL");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':contactGuid', $contactGuid, PDO::PARAM_STR);
        $stmt->execute();
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) {
            return $response->withStatus(404)->withJson(["error" => "Contact not found"]);
        }

        $response->getBody()->write(json_encode($contact));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Skapa en ny kontakt
    $app->post('/{organizationId}/contacts', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        // Kontrollera att obligatoriska fält finns
        if (!isset($data['name'], $data['country_key'], $data['is_person'], $data['is_member'], $data['use_cvr'], $data['is_debitor'], $data['is_creditor'])) {
            return $response->withStatus(400)->withJson(["error" => "Missing required fields"]);
        }

        $contactGuid = uniqid();
        $stmt = $pdo->prepare("
            INSERT INTO contacts (
                organization_id, contact_guid, external_reference, name, street, zip_code, city, country_key, phone, email, webpage, 
                att_person, vat_number, ean_number, se_number, p_number, payment_condition_type, payment_condition_number_of_days, 
                is_person, is_member, member_number, use_cvr, company_type_key, invoice_mail_out_option_key, created_at, updated_at, 
                deleted_at, is_debitor, is_creditor, company_status, vat_region_key
            ) VALUES (
                :organizationId, :contactGuid, :externalReference, :name, :street, :zipCode, :city, :countryKey, :phone, :email, :webpage,
                :attPerson, :vatNumber, :eanNumber, :seNumber, :pNumber, :paymentConditionType, :paymentConditionNumberOfDays, 
                :isPerson, :isMember, :memberNumber, :useCvr, :companyTypeKey, :invoiceMailOutOptionKey, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 
                NULL, :isDebitor, :isCreditor, :companyStatus, :vatRegionKey
            )
        ");

        $stmt->execute([
            ':organizationId' => $organizationId,
            ':contactGuid' => $contactGuid,
            ':externalReference' => $data['external_reference'] ?? null,
            ':name' => $data['name'],
            ':street' => $data['street'] ?? null,
            ':zipCode' => $data['zip_code'] ?? null,
            ':city' => $data['city'] ?? null,
            ':countryKey' => $data['country_key'],
            ':phone' => $data['phone'] ?? null,
            ':email' => $data['email'] ?? null,
            ':webpage' => $data['webpage'] ?? null,
            ':attPerson' => $data['att_person'] ?? null,
            ':vatNumber' => $data['vat_number'] ?? null,
            ':eanNumber' => $data['ean_number'] ?? null,
            ':seNumber' => $data['se_number'] ?? null,
            ':pNumber' => $data['p_number'] ?? null,
            ':paymentConditionType' => $data['payment_condition_type'] ?? null,
            ':paymentConditionNumberOfDays' => $data['payment_condition_number_of_days'] ?? null,
            ':isPerson' => $data['is_person'],
            ':isMember' => $data['is_member'],
            ':memberNumber' => $data['member_number'] ?? null,
            ':useCvr' => $data['use_cvr'],
            ':companyTypeKey' => $data['company_type_key'] ?? null,
            ':invoiceMailOutOptionKey' => $data['invoice_mail_out_option_key'] ?? null,
            ':isDebitor' => $data['is_debitor'],
            ':isCreditor' => $data['is_creditor'],
            ':companyStatus' => $data['company_status'] ?? null,
            ':vatRegionKey' => $data['vat_region_key'] ?? null
        ]);

        return $response->withStatus(201)->withJson(["message" => "Contact created successfully", "contact_guid" => $contactGuid]);
    });

    // Soft-delete en kontakt
    $app->delete('/{organizationId}/contacts/{contactGuid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $contactGuid = $args['contactGuid'];

        // Kontrollera om kontakten finns
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE organization_id = :organizationId AND contact_guid = :contactGuid AND deleted_at IS NULL");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':contactGuid', $contactGuid, PDO::PARAM_STR);
        $stmt->execute();
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) {
            return $response->withStatus(404)->withJson(["error" => "Contact not found"]);
        }

        // Markera kontakten som raderad (soft delete)
        $stmt = $pdo->prepare("UPDATE contacts SET deleted_at = CURRENT_TIMESTAMP WHERE organization_id = :organizationId AND contact_guid = :contactGuid");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':contactGuid', $contactGuid, PDO::PARAM_STR);
        $stmt->execute();

        return $response->withStatus(200)->withJson(["message" => "Contact deleted successfully"]);
    });
};
