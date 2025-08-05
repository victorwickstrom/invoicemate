<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\InvoiceService;

class InvoiceServiceTest extends TestCase
{
    public function testListInvoicesReturnsEmptyArrayForEmptyDatabase(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE invoice (guid TEXT PRIMARY KEY, organization_id INTEGER, status TEXT, number INTEGER, invoice_date TEXT, external_reference TEXT, contact_guid TEXT, description TEXT, contact_name TEXT, total_incl_vat REAL, total_excl_vat REAL, total_vatable_amount REAL, total_non_vatable_amount REAL, total_vat REAL)');
        $service = new InvoiceService($pdo);
        $result = $service->listInvoices(1, []);
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }
}