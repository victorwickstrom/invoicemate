<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * InvoiceValidator performs lightweight validation on invoice input data.
 *
 * In a production system you might choose to use a dedicated validation
 * library such as "rakit/validation" or "respect/validation".  To keep
 * dependencies minimal, this validator implements a handful of checks by
 * hand.  The validate() method returns an associative array of errors
 * keyed by field name; if the array is empty the data is considered
 * valid.
 */
class InvoiceValidator
{
    /**
     * Validate incoming invoice payload.
     *
     * @param array $data
     * @return array<string,string> Array of errors keyed by field name.
     */
    public function validate(array $data): array
    {
        $errors = [];

        // Require at least one invoice line
        if (empty($data['productLines']) || !is_array($data['productLines'])) {
            $errors['productLines'] = 'productLines is required and must be an array';
        }

        // Validate invoice number
        if (isset($data['number']) && !is_numeric($data['number'])) {
            $errors['number'] = 'number must be numeric';
        }

        // Validate currency (basic ISO 4217 uppercase letters check)
        if (isset($data['currency']) && !preg_match('/^[A-Z]{3}$/', (string) $data['currency'])) {
            $errors['currency'] = 'currency must be a 3‑letter ISO code';
        }

        return $errors;
    }
}