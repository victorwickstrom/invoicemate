<?php
declare(strict_types=1);

namespace App\DTO;

/**
 * InvoiceDTO is a simple data transfer object representing the shape of an
 * invoice payload.  It exposes properties for the fields supported by the
 * Invoicemate API and provides a factory method for construction from
 * arbitrary input arrays.  Consumers should perform validation prior to
 * constructing an instance.
 */
class InvoiceDTO
{
    public string $guid;
    public int    $organizationId;
    public ?string $currency;
    public ?string $language;
    public ?string $external_reference;
    public ?string $description;
    public ?string $comment;
    public string $invoice_date;
    public ?int    $number;
    public ?string $contact_name;
    public int    $show_lines_incl_vat;
    public float  $total_excl_vat;
    public float  $total_vatable_amount;
    public float  $total_incl_vat;
    public float  $total_non_vatable_amount;
    public float  $total_vat;
    public string $status;
    public ?string $contact_guid;

    /**
     * Construct an InvoiceDTO from an associative array.  Missing optional
     * fields will be set to null.  Defaults are provided for dates and
     * numeric values.
     */
    public static function fromArray(int $organizationId, array $data): self
    {
        $dto = new self();
        $dto->guid                    = $data['guid'] ?? '';
        $dto->organizationId          = $organizationId;
        $dto->currency                = $data['currency'] ?? null;
        $dto->language                = $data['language'] ?? null;
        $dto->external_reference      = $data['external_reference'] ?? null;
        $dto->description             = $data['description'] ?? null;
        $dto->comment                 = $data['comment'] ?? null;
        $dto->invoice_date            = $data['date'] ?? date('Y-m-d');
        $dto->number                  = isset($data['number']) ? (int) $data['number'] : null;
        $dto->contact_name            = $data['contact_name'] ?? null;
        $dto->show_lines_incl_vat     = (int)($data['show_lines_incl_vat'] ?? 1);
        $dto->total_excl_vat          = (float)($data['total_excl_vat'] ?? 0);
        $dto->total_vatable_amount    = (float)($data['total_vatable_amount'] ?? 0);
        $dto->total_incl_vat          = (float)($data['total_incl_vat'] ?? 0);
        $dto->total_non_vatable_amount = (float)($data['total_non_vatable_amount'] ?? 0);
        $dto->total_vat               = (float)($data['total_vat'] ?? 0);
        $dto->status                  = $data['status'] ?? 'Draft';
        $dto->contact_guid            = $data['contact_guid'] ?? null;
        return $dto;
    }
}