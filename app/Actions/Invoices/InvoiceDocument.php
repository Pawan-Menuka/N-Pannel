<?php

namespace App\Actions\Invoices;

final readonly class InvoiceDocument
{
    /**
     * @param  array{name: string, address: string, phone: string, email: string}  $seller
     * @param  array{name: string, company_name: ?string, phone_e164: ?string, email: ?string, address_line1: ?string, address_line2: ?string, city: ?string, vat_number: ?string}  $buyer
     * @param  list<array{description: string, sku: ?string, qty: int, saleUnit: ?string, unitPrice: string, lineTotal: string}>  $lines
     * @param  list<array{receiptNumber: ?string, date: string, method: string, amount: string, receiptUrl: ?string}>  $payments
     * @param  array{account_name: string, bank_name: string, branch: string, account_number: string}|null  $bank
     */
    public function __construct(
        public array $seller,
        public string $title,
        public ?string $sellerVatNumber,
        public string $number,
        public string $issuedDate,
        public ?string $dueDate,
        public array $buyer,
        public array $lines,
        public string $subtotal,
        public string $delivery,
        public string $discount,
        public string $total,
        public ?string $vatLine,
        public array $payments,
        public string $paid,
        public string $balance,
        public ?array $bank,
        public string $footer,
        public ?string $voidNotice,
    ) {}
}
