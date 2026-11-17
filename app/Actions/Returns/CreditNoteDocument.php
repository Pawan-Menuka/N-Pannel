<?php

namespace App\Actions\Returns;

final readonly class CreditNoteDocument
{
    /**
     * @param  array{name: string, address: string, phone: string, email: string}  $seller
     * @param  array{name: string, company_name: ?string, phone_e164: ?string, email: ?string, address_line1: ?string, address_line2: ?string, city: ?string, vat_number: ?string}  $buyer
     * @param  list<array{description: string, sku: ?string, qty: int, saleUnit: ?string, unitPrice: string, lineTotal: string}>  $lines
     */
    public function __construct(
        public array $seller,
        public ?string $sellerVatNumber,
        public string $number,
        public string $issuedDate,
        public string $invoiceNumber,
        public array $buyer,
        public array $lines,
        public string $total,
        public ?string $vatLine,
        public string $reason,
        public string $footer,
    ) {}
}
