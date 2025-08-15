<?php
/**
 * Routes for managing contacts within an organization.
 *
 * This file refactors the original contact routes to provide sensible
 * defaults for boolean flags, basic validation for email and phone
 * numbers, CVR‑lookup support and soft‑delete side effects. It also
 * avoids duplication of contacts by checking for existing records on
 * creation. All database interactions go through the PDO alias bound
 * in the container (\PDO::class) rather than the generic `'db'`
 * identifier. By breaking helper logic out into functions we prepare
 * the code for later extraction into service classes.
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

return function ($app) {
    $container = $app->getContainer();

    /**
     * Attempt to fetch company information from the official Danish CVR
     * registry. If a CVR API endpoint is available the function returns
     * an associative array with keys name, street, zip_code and city.
     * Otherwise it returns an empty array. Errors are silently ignored
     * as the calling code will fall back to provided user input.
     *
     * @param string $cvr
     * @return array
     */
    $fetchCompanyDataFromCVR = function (string $cvr): array {
        $result = [];
        $cvr = preg_replace('/\D/', '', $cvr); // strip non‑digits
        if (strlen($cvr) < 8) {
            return $result;
        }
        // Prepare URL. The public cvrapi.dk returns JSON for a CVR search.
        $url = 'https://cvrapi.dk/api?search=' . urlencode($cvr) . '&country=dk';
        $opts = [
            'http' => [
                'method' => 'GET',
                'timeout' => 4,
                'header' => "Accept: application/json\r\n"
            ],
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        if ($json === false) {
            return $result;
        }
        $data = json_decode($json, true);
        if (is_array($data)) {
            $result['name'] = $data['name'] ?? null;
            $result['street'] = $data['address'] ?? null;
            // The CVR API returns zip and city combined or separate depending on version
            if (isset($data['zipcode'])) {
                $result['zip_code'] = $data['zipcode'];
            }
            if (isset($data['city'])) {
                $result['city'] = $data['city'];
            }
        }
        return array_filter($result, static fn($v) => !is_null($v));
    };

    /**
     * Validate an email address using PHP's filter. Returns true for a
     * syntactically valid address or false otherwise.
     *
     * @param string|null $email
     * @return bool
     */
    $validateEmail = function (?string $email): bool {
        if ($email === null || $email === '') {
            return true;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    };

    /**
     * Perform a very basic phone number validation. Accepts digits,
     * whitespace, plus signs and parentheses. Returns true for valid
     * numbers or empty values.
     *
     * @param string|null $phone
     * @return bool
     */
    $validatePhone = function (?string $phone): bool {
        if ($phone === null || $phone === '') {
            return true;
        }
        return (bool) preg_match('/^[0-9+()\s-]{5,}$/', $phone);
    };

    // List contacts for an organization
    $app->get('/{organizationId}/contacts', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE organization_id = :orgId AND deleted_at IS NULL ORDER BY name ASC");
        $stmt->execute([':orgId' => $orgId]);
        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($contacts));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Retrieve a specific contact
    $app->get('/{organizationId}/contacts/{contactGuid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $contactGuid = $args['contactGuid'];
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE organization_id = :orgId AND contact_guid = :guid AND deleted_at IS NULL");
        $stmt->execute([':orgId' => $orgId, ':guid' => $contactGuid]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contact) {
            $response->getBody()->write(json_encode(['error' => 'Contact not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode($contact));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Create a new contact
    $app->post('/{organizationId}/contacts', function (Request $request, Response $response, array $args) use ($container, $fetchCompanyDataFromCVR, $validateEmail, $validatePhone) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $data = $request->getParsedBody();

        // Set defaults for booleans
        $boolFields = [
            'is_person' => 0,
            'is_member' => 0,
            'use_cvr' => 0,
            'is_debitor' => 0,
            'is_creditor' => 0
        ];
        foreach ($boolFields as $field => $default) {
            if (!isset($data[$field])) {
                $data[$field] = $default;
            }
        }
        // Validate email and phone
        if (!$validateEmail($data['email'] ?? null)) {
            $response->getBody()->write(json_encode(['error' => 'Invalid email format']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        if (!$validatePhone($data['phone'] ?? null)) {
            $response->getBody()->write(json_encode(['error' => 'Invalid phone number']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        // Prevent duplicates by email
        if (!empty($data['email'])) {
            $dupStmt = $pdo->prepare("SELECT contact_guid FROM contacts WHERE organization_id = :orgId AND email = :email AND deleted_at IS NULL");
            $dupStmt->execute([':orgId' => $orgId, ':email' => $data['email']]);
            if ($dupStmt->fetch()) {
                $response->getBody()->write(json_encode(['error' => 'A contact with the same email already exists']));
                return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
            }
        }
        // If use_cvr is true and vat_number provided, attempt to enrich data
        if (!empty($data['use_cvr']) && !empty($data['vat_number'])) {
            $cvrData = $fetchCompanyDataFromCVR($data['vat_number']);
            // Only override fields that are not provided
            foreach ($cvrData as $key => $value) {
                if (empty($data[$key])) {
                    $data[$key] = $value;
                }
            }
        }
        $contactGuid = uniqid('contact_', true);
        // Prepare insert statement
        $stmt = $pdo->prepare("INSERT INTO contacts (organization_id, contact_guid, external_reference, name, street, zip_code, city, country_key, phone, email, webpage, att_person, vat_number, ean_number, se_number, p_number, payment_condition_type, payment_condition_number_of_days, is_person, is_member, member_number, use_cvr, company_type_key, invoice_mail_out_option_key, created_at, updated_at, deleted_at, is_debitor, is_creditor, company_status, vat_region_key) VALUES (:orgId, :guid, :externalReference, :name, :street, :zipCode, :city, :countryKey, :phone, :email, :webpage, :attPerson, :vatNumber, :eanNumber, :seNumber, :pNumber, :paymentConditionType, :paymentConditionNumberOfDays, :isPerson, :isMember, :memberNumber, :useCvr, :companyTypeKey, :invoiceMailOutOptionKey, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL, :isDebitor, :isCreditor, :companyStatus, :vatRegionKey)");
        $stmt->execute([
            ':orgId' => $orgId,
            ':guid' => $contactGuid,
            ':externalReference' => $data['external_reference'] ?? null,
            ':name' => $data['name'] ?? ($cvrData['name'] ?? null),
            ':street' => $data['street'] ?? null,
            ':zipCode' => $data['zip_code'] ?? null,
            ':city' => $data['city'] ?? null,
            ':countryKey' => $data['country_key'] ?? 'DK',
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
            ':isPerson' => (int) $data['is_person'],
            ':isMember' => (int) $data['is_member'],
            ':memberNumber' => $data['member_number'] ?? null,
            ':useCvr' => (int) $data['use_cvr'],
            ':companyTypeKey' => $data['company_type_key'] ?? null,
            ':invoiceMailOutOptionKey' => $data['invoice_mail_out_option_key'] ?? null,
            ':isDebitor' => (int) $data['is_debitor'],
            ':isCreditor' => (int) $data['is_creditor'],
            ':companyStatus' => $data['company_status'] ?? null,
            ':vatRegionKey' => $data['vat_region_key'] ?? null
        ]);
        $response->getBody()->write(json_encode(['message' => 'Contact created successfully', 'contact_guid' => $contactGuid]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // Update an existing contact (full update)
    $app->put('/{organizationId}/contacts/{contactGuid}', function (Request $request, Response $response, array $args) use ($container, $fetchCompanyDataFromCVR, $validateEmail, $validatePhone) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['contactGuid'];
        $data = $request->getParsedBody();
        // Check if contact exists
        $stmtCheck = $pdo->prepare("SELECT * FROM contacts WHERE organization_id = :orgId AND contact_guid = :guid AND deleted_at IS NULL");
        $stmtCheck->execute([':orgId' => $orgId, ':guid' => $guid]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $response->getBody()->write(json_encode(['error' => 'Contact not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        // Validate email and phone if provided
        if (array_key_exists('email', $data) && !$validateEmail($data['email'])) {
            $response->getBody()->write(json_encode(['error' => 'Invalid email format']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        if (array_key_exists('phone', $data) && !$validatePhone($data['phone'])) {
            $response->getBody()->write(json_encode(['error' => 'Invalid phone number']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        // If use_cvr toggled to true and vat_number changed, refresh from CVR
        $cvrData = [];
        if (!empty($data['use_cvr']) && !empty($data['vat_number'])) {
            $cvrData = $fetchCompanyDataFromCVR($data['vat_number']);
        }
        // Build update query with provided fields
        $fields = [
            'external_reference' => ':externalReference',
            'name' => ':name',
            'street' => ':street',
            'zip_code' => ':zipCode',
            'city' => ':city',
            'country_key' => ':countryKey',
            'phone' => ':phone',
            'email' => ':email',
            'webpage' => ':webpage',
            'att_person' => ':attPerson',
            'vat_number' => ':vatNumber',
            'ean_number' => ':eanNumber',
            'se_number' => ':seNumber',
            'p_number' => ':pNumber',
            'payment_condition_type' => ':paymentConditionType',
            'payment_condition_number_of_days' => ':paymentConditionNumberOfDays',
            'is_person' => ':isPerson',
            'is_member' => ':isMember',
            'member_number' => ':memberNumber',
            'use_cvr' => ':useCvr',
            'company_type_key' => ':companyTypeKey',
            'invoice_mail_out_option_key' => ':invoiceMailOutOptionKey',
            'is_debitor' => ':isDebitor',
            'is_creditor' => ':isCreditor',
            'company_status' => ':companyStatus',
            'vat_region_key' => ':vatRegionKey'
        ];
        $setParts = [];
        $binds = [];
        foreach ($fields as $dbField => $placeholder) {
            if (array_key_exists($dbField, $data)) {
                $setParts[] = "$dbField = $placeholder";
                $binds[$placeholder] = $data[$dbField];
            }
        }
        // Merge CVR data for missing fields
        foreach ($cvrData as $key => $value) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                $setParts[] = "$key = :$key";
                $binds[":" . $key] = $value;
            }
        }
        if (empty($setParts)) {
            // Nothing to update
            $response->getBody()->write(json_encode(['message' => 'No fields to update']));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $sql = "UPDATE contacts SET " . implode(', ', $setParts) . ", updated_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND contact_guid = :guid";
        $stmt = $pdo->prepare($sql);
        $binds[':orgId'] = $orgId;
        $binds[':guid'] = $guid;
        // Convert booleans to ints
        if (isset($data['is_person'])) { $binds[':isPerson'] = (int) $data['is_person']; }
        if (isset($data['is_member'])) { $binds[':isMember'] = (int) $data['is_member']; }
        if (isset($data['use_cvr'])) { $binds[':useCvr'] = (int) $data['use_cvr']; }
        if (isset($data['is_debitor'])) { $binds[':isDebitor'] = (int) $data['is_debitor']; }
        if (isset($data['is_creditor'])) { $binds[':isCreditor'] = (int) $data['is_creditor']; }
        $stmt->execute($binds);
        $response->getBody()->write(json_encode(['message' => 'Contact updated successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Soft delete a contact
    $app->delete('/{organizationId}/contacts/{contactGuid}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['contactGuid'];
        // Soft delete contact
        $stmt = $pdo->prepare("UPDATE contacts SET deleted_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND contact_guid = :guid AND deleted_at IS NULL");
        $stmt->execute([':orgId' => $orgId, ':guid' => $guid]);
        // Also mark any invoices and credit notes for this contact as deleted to prevent further processing
        $pdo->prepare("UPDATE invoice SET deleted_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND contact_guid = :guid AND deleted_at IS NULL")->execute([':orgId' => $orgId, ':guid' => $guid]);
        $pdo->prepare("UPDATE credit_note SET deleted_at = CURRENT_TIMESTAMP WHERE organization_id = :orgId AND contact_guid = :guid AND deleted_at IS NULL")->execute([':orgId' => $orgId, ':guid' => $guid]);
        $response->getBody()->write(json_encode(['message' => 'Contact deleted successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // List notes for a contact
    $app->get('/{organizationId}/contacts/{contactGuid}/notes', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get(PDO::class);
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
        $pdo = $container->get(PDO::class);
        $orgId = $args['organizationId'];
        $guid = $args['contactGuid'];
        $data = $request->getParsedBody();
        if (!isset($data['note'], $data['author_name'])) {
            $response->getBody()->write(json_encode(['error' => 'Missing note or author_name']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
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
        $pdo = $container->get(PDO::class);
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
        $pdo = $container->get(PDO::class);
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