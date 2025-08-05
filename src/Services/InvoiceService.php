<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * InvoiceService encapsulates all database interactions related to invoices.
 *
 * Moving database logic out of the route callbacks makes the API easier to
 * test and reason about.  Each method on this service performs a single
 * operation against the underlying PDO instance.  If more complex business
 * rules are required you can expand these methods or delegate further into
 * domain classes.
 */
class InvoiceService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Retrieve a paginated list of invoices for the given organization.
     *
     * @param int   $organizationId
     * @param array $params Query parameters used for filtering and sorting
     *
     * @return array[]
     */
    public function listInvoices(int $organizationId, array $params): array
    {
        $statusFilter  = isset($params['statusFilter']) ? explode(',', (string) $params['statusFilter']) : [];
        $queryFilter   = $params['queryFilter']   ?? null;
        $freeText      = $params['freeTextSearch'] ?? null;
        $startDate     = $params['startDate']     ?? null;
        $endDate       = $params['endDate']       ?? null;
        $page          = isset($params['page'])    ? max(0, (int) $params['page']) : 0;
        $pageSize      = isset($params['pageSize'])? min(1000, max(1, (int) $params['pageSize'])) : 100;
        $sort          = isset($params['sort'])    ? explode(',', (string) $params['sort']) : ['number', 'invoice_date'];
        $sortOrder     = (isset($params['sortOrder']) && strtolower((string) $params['sortOrder']) === 'ascending') ? 'ASC' : 'DESC';

        $query   = "SELECT * FROM invoice WHERE organization_id = :organizationId";
        $bindings = [':organizationId' => $organizationId];

        // Status filter
        if (!empty($statusFilter)) {
            $placeholders = [];
            foreach ($statusFilter as $index => $status) {
                $ph = ":status{$index}";
                $placeholders[] = $ph;
                $bindings[$ph] = $status;
            }
            $query .= ' AND status IN (' . implode(',', $placeholders) . ')';
        }

        // Query filter on external_reference, contact_guid or description
        if ($queryFilter) {
            $query .= ' AND (external_reference LIKE :queryFilter OR contact_guid LIKE :queryFilter OR description LIKE :queryFilter)';
            $bindings[':queryFilter'] = '%' . $queryFilter . '%';
        }

        // Free text search across multiple columns
        if ($freeText) {
            $query .= ' AND (number LIKE :freeText OR contact_name LIKE :freeText OR description LIKE :freeText OR total_incl_vat LIKE :freeText)';
            $bindings[':freeText'] = '%' . $freeText . '%';
        }

        // Date range filter
        if ($startDate && $endDate) {
            $query .= ' AND invoice_date BETWEEN :startDate AND :endDate';
            $bindings[':startDate'] = $startDate;
            $bindings[':endDate']   = $endDate;
        }

        $query .= ' ORDER BY ' . implode(',', $sort) . " {$sortOrder} LIMIT :limit OFFSET :offset";
        $bindings[':limit']  = $pageSize;
        $bindings[':offset'] = $page * $pageSize;

        $stmt = $this->pdo->prepare($query);
        // Bind parameters with correct types
        foreach ($bindings as $key => $value) {
            if (in_array($key, [':limit', ':offset'])) {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create a new invoice and return its GUID.
     *
     * This method expects that validation has already occurred on $data.  It
     * extracts a subset of fields from the input array and persists them to
     * the database.  If the GUID is not provided it will be generated.
     *
     * @param int   $organizationId
     * @param array $data
     *
     * @return string The GUID of the newly created invoice.
     */
    public function createInvoice(int $organizationId, array $data): string
    {
        // Generate GUID if not provided
        $guid = $data['guid'] ?? Uuid::uuid4()->toString();

        $invoiceDate = $data['date'] ?? date('Y-m-d');

        $sql = "INSERT INTO invoice (guid, organization_id, currency, language, external_reference, description, comment, invoice_date, number, contact_name, show_lines_incl_vat, total_excl_vat, total_vatable_amount, total_incl_vat, total_non_vatable_amount, total_vat, status, contact_guid, payment_date, payment_status, payment_condition_number_of_days, payment_condition_type, fik_code, deposit_account_number, mail_out_status, latest_mail_out_type, is_sent_to_debt_collection, is_mobile_pay_invoice_enabled, is_penso_pay_enabled, reminder_fee, reminder_interest_rate) VALUES (:guid, :organizationId, :currency, :language, :external_reference, :description, :comment, :invoice_date, :number, :contact_name, :show_lines_incl_vat, :total_excl_vat, :total_vatable_amount, :total_incl_vat, :total_non_vatable_amount, :total_vat, :status, :contact_guid, :payment_date, :payment_status, :payment_condition_number_of_days, :payment_condition_type, :fik_code, :deposit_account_number, :mail_out_status, :latest_mail_out_type, :is_sent_to_debt_collection, :is_mobile_pay_invoice_enabled, :is_penso_pay_enabled, :reminder_fee, :reminder_interest_rate)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':guid'      => $guid,
            ':organizationId'            => $organizationId,
            ':currency'  => $data['currency'] ?? null,
            ':language'  => $data['language'] ?? null,
            ':external_reference' => $data['external_reference'] ?? null,
            ':description' => $data['description'] ?? null,
            ':comment'   => $data['comment'] ?? null,
            ':invoice_date' => $invoiceDate,
            ':number'    => $data['number'] ?? null,
            ':contact_name' => $data['contact_name'] ?? null,
            ':show_lines_incl_vat' => $data['show_lines_incl_vat'] ?? 1,
            ':total_excl_vat' => $data['total_excl_vat'] ?? 0,
            ':total_vatable_amount' => $data['total_vatable_amount'] ?? 0,
            ':total_incl_vat' => $data['total_incl_vat'] ?? 0,
            ':total_non_vatable_amount' => $data['total_non_vatable_amount'] ?? 0,
            ':total_vat' => $data['total_vat'] ?? 0,
            ':status'    => $data['status'] ?? 'Draft',
            ':contact_guid' => $data['contact_guid'] ?? null,
            ':payment_date' => $data['payment_date'] ?? null,
            ':payment_status' => $data['payment_status'] ?? null,
            ':payment_condition_number_of_days' => $data['payment_condition_number_of_days'] ?? 0,
            ':payment_condition_type' => $data['payment_condition_type'] ?? null,
            ':fik_code' => $data['fik_code'] ?? null,
            ':deposit_account_number' => $data['deposit_account_number'] ?? null,
            ':mail_out_status' => $data['mail_out_status'] ?? null,
            ':latest_mail_out_type' => $data['latest_mail_out_type'] ?? null,
            ':is_sent_to_debt_collection' => $data['is_sent_to_debt_collection'] ?? 0,
            ':is_mobile_pay_invoice_enabled' => $data['is_mobile_pay_invoice_enabled'] ?? 0,
            ':is_penso_pay_enabled' => $data['is_penso_pay_enabled'] ?? 0,
            ':reminder_fee' => $data['reminder_fee'] ?? null,
            ':reminder_interest_rate' => $data['reminder_interest_rate'] ?? null,
        ]);

        return $guid;
    }
}