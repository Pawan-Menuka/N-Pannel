<?php

namespace App\Actions\Payments;

final readonly class ReceiptDocument
{
    /** @param array{name: string, address: string, phone: string, email: string} $seller */
    public function __construct(
        public array $seller,
        public string $number,
        public string $date,
        public string $customerName,
        public string $orderReference,
        public ?string $invoiceNumber,
        public string $method,
        public ?string $reference,
        public string $amount,
        public string $total,
        public string $paid,
        public string $balance,
        public string $footer,
    ) {}
}
