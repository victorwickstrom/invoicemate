<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

/**
 * Contact routes.
 *
 * Provides CRUD operations for contacts plus management of contact notes and
 * static lists such as available countries. Contacts are scoped to an
 * organization and boolean flags are normalised to integers. Soft delete is
 * used instead of physical removal.
 */
return function (App $app) {
    $container = $app->getContainer();

    // List contacts for an organization
    $app->get('/{organizationId}/contacts', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE organization_id = :orgId AND deleted_at IS NULL ORDER BY name ASC");
        $stmt->execute([':orgId' => $orgId]);
        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($contacts));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Retrieve a specific contact
    $app->get('/{organizationId}/contacts/{contactGuid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $contactGuid = $args['contactGuid'];
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE organization_id = :orgId AND contact_guid = :guid AND deleted_at IS NULL");
        $stmt->execute([':orgId' => $orgId, ':guid' => $contactGuid]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contact) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Contact not found']));
        }
        $response->getBody()->write(json_encode($contact));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Create a new contact
    $app->post('/{organizationId}/contacts', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $data = $request->getParsedBody();
        // Validate mandatory fields
        $required = ['name', 'country_key', 'is_person', 'is_member', 'use_cvr', 'is_debitor', 'is_creditor'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => "Missing required field: $field"]));
            }
        }
        $contactGuid = uniqid('contact_', true);
        // Prepare boolean conversion
        $bool = function ($val) { return empty($val) ? 0 : 1; };
        $stmt = $pdo->prepare("INSERT INTO contacts (organization_id, contact_guid, external_reference, name, street, zip_code, city, country_key, phone, email, webpage, att_person, vat_number, ean_number, se_number, p_number, payment_condition_type, payment_condition_number_of_days, is_person, is_member, member_number, use_cvr, company_type_key, invoice_mail_out_option_key, created_at, updated_at, deleted_at, is_debitor, is_creditor, company_status, vat_region_key) VALUES (:orgId, :guid, :externalReference, :name, :street, :zipCode, :city, :countryKey, :phone, :email, :webpage, :attPerson, :vatNumber, :eanNumber, :seNumber, :pNumber, :paymentConditionType, :paymentConditionNumberOfDays, :isPerson, :isMember, :memberNumber, :useCvr, :companyTypeKey, :invoiceMailOutOptionKey, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL, :isDebitor, :isCreditor, :companyStatus, :vatRegionKey)");
        $stmt->execute([
            ':orgId' => $orgId,
            ':guid' => $contactGuid,
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
            ':isPerson' => $bool($data['is_person']),
            ':isMember' => $bool($data['is_member']),
            ':memberNumber' => $data['member_number'] ?? null,
            ':useCvr' => $bool($data['use_cvr']),
            ':companyTypeKey' => $data['company_type_key'] ?? null,
            ':invoiceMailOutOptionKey' => $data['invoice_mail_out_option_key'] ?? null,
            ':isDebitor' => $bool($data['is_debitor']),
            ':isCreditor' => $bool($data['is_creditor']),
            ':companyStatus' => $data['company_status'] ?? null,
            ':vatRegionKey' => $data['vat_region_key'] ?? null
        ]);
        $response->getBody()->write(json_encode(['message' => 'Contact created successfully', 'contact_guid' => $contactGuid]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Update an existing contact (full update)
    $app->put('/{organizationId}/contacts/{contactGuid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $guid = $args['contactGuid'];
        $data = $request->getParsedBody();
        // Only allow update if contact exists
        $stmtCheck = $pdo->prepare("SELECT * FROM contacts WHERE organization_id = :orgId AND contact_guid = :guid AND deleted_at IS NULL");
        $stmtCheck->execute([':orgId' => $orgId, ':guid' => $guid]);
        if (!$stmtCheck->fetch(PDO::FETCH_ASSOC)) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Contact not found']));
        }
        // Prepare boolean conversion
        $bool = function ($val) { return empty($val) ? 0 : 1; };
        $stmt = $pdo->prepare("UPDATE contacts SET external_reference = :externalReference, name = :name, street = :street, zip_code = :zipCode, city = :city, country_key = :countryKey, phone = :phone, email = :email, webpage = :webpage, att_person = :attPerson, vat_number = :vatNumber, ean_number = :eanNumber, se_number = :seNumber, p_number = :pNumber, payment_condition_type = :paymentConditionType, payment_condition_number_of_days = :paymentConditionNumberOfDays, is_person = :isPerson, is_member = :isMember, member_number = :memberNumber, use_cvr = :useCvr, company_type_key = :companyTypeKey, invoice_mail_out_option_key = :invoiceMailOutOptionKey, updated_at = CURRENT_TIMESTAMP, is_debitor = :isDebitor, is_creditor = :isCreditor, company_status = :companyStatus, vat_region_key = :vatRegionKey WHERE organization_id = :orgId AND contact_guid = :guid");
        $stmt->execute([
            ':externalReference' => $data['external_reference'] ?? null,
            ':name' => $data['name'] ?? null,
            ':street' => $data['street'] ?? null,
            ':zipCode' => $data['zip_code'] ?? null,
            ':city' => $data['city'] ?? null,
            ':countryKey' => $data['country_key'] ?? null,
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
            ':isPerson' => isset($data['is_person']) ? $bool($data['is_person']) : null,
            ':isMember' => isset($data['is_member']) ? $bool($data['is_member']) : null,
            ':memberNumber' => $data['member_number'] ?? null,
            ':useCvr' => isset($data['use_cvr']) ? $bool($data['use_cvr']) : null,
            ':companyTypeKey' => $data['company_type_key'] ?? null,
            ':invoiceMailOutOptionKey' => $data['invoice_mail_out_option_key'] ?? null,
            ':isDebitor' => isset($data['is_debitor']) ? $bool($data['is_debitor']) : null,
            ':isCreditor' => isset($data['is_creditor']) ? $bool($data['is_creditor']) : null,
            ':companyStatus' => $data['company_status'] ?? null,
            ':vatRegionKey' => $data['vat_region_key'] ?? null,
            ':orgId' => $orgId,
            ':guid' => $guid
        ]);
        $response->getBody()->write(json_encode(['message' => 'Contact updated successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Soft delete a contact
    $app->delete('/{organizationId}/contacts/{contactGuid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $guid = $args['contactGuid'];
        $stmt = $pdo->prepare("UPDATE contacts SET deleted_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND contact_guid = :guid AND deleted_at IS NULL");
        $stmt->execute([':orgId' => $orgId, ':guid' => $guid]);
        $response->getBody()->write(json_encode(['message' => 'Contact deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // List notes for a contact
    $app->get('/{organizationId}/contacts/{contactGuid}/notes', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $guid = $args['contactGuid'];
        $stmt = $pdo->prepare("SELECT * FROM contact_notes WHERE organization_id = :orgId AND contact_guid = :guid AND deleted_at IS NULL ORDER BY note_date DESC");
        $stmt->execute([':orgId' => $orgId, ':guid' => $guid]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($notes));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Create a note for a contact
    $app->post('/{organizationId}/contacts/{contactGuid}/notes', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $guid = $args['contactGuid'];
        $data = $request->getParsedBody();
        if (!isset($data['note'], $data['author_name'])) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Missing note or author_name']));
        }
        $stmt = $pdo->prepare("INSERT INTO contact_notes (organization_id, contact_guid, note, author_name, note_date, deleted_at) VALUES (:orgId, :guid, :note, :authorName, :noteDate, NULL)");
        $stmt->execute([
            ':orgId' => $orgId,
            ':guid' => $guid,
            ':note' => $data['note'],
            ':authorName' => $data['author_name'],
            ':noteDate' => date('Y-m-d H:i:s')
        ]);
        $response->getBody()->write(json_encode(['message' => 'Note added successfully']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Update a contact note
    $app->put('/{organizationId}/contacts/{contactGuid}/notes/{noteId}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $guid = $args['contactGuid'];
        $noteId = $args['noteId'];
        $data = $request->getParsedBody();
        $stmt = $pdo->prepare("UPDATE contact_notes SET note = :note, author_name = :authorName, note_date = :noteDate WHERE id = :id AND organization_id = :orgId AND contact_guid = :guid");
        $stmt->execute([
            ':note' => $data['note'] ?? null,
            ':authorName' => $data['author_name'] ?? null,
            ':noteDate' => date('Y-m-d H:i:s'),
            ':id' => $noteId,
            ':orgId' => $orgId,
            ':guid' => $guid
        ]);
        $response->getBody()->write(json_encode(['message' => 'Note updated successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Delete a contact note (soft delete)
    $app->delete('/{organizationId}/contacts/{contactGuid}/notes/{noteId}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $orgId = $args['organizationId'];
        $guid = $args['contactGuid'];
        $noteId = $args['noteId'];
        $stmt = $pdo->prepare("UPDATE contact_notes SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id AND organization_id = :orgId AND contact_guid = :guid");
        $stmt->execute([':id' => $noteId, ':orgId' => $orgId, ':guid' => $guid]);
        $response->getBody()->write(json_encode(['message' => 'Note deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Static list of countries
    $app->get('/countries', function (Request $request, Response $response) {
        $countries = [
            ['code' => 'DK', 'name' => 'Danmark'],
            ['code' => 'SE', 'name' => 'Sverige'],
            ['code' => 'NO', 'name' => 'Norge'],
            ['code' => 'FI', 'name' => 'Finland'],
            ['code' => 'DE', 'name' => 'Tyskland']
        ];
        $response->getBody()->write(json_encode($countries));
        return $response->withHeader('Content-Type', 'application/json');
    });
};