<?php

namespace App\Actions\Orders;

final readonly class QuoteDocument
{
    /** @param list<array{description: string, sku: string|null, qty: int, saleUnit: string|null, unitPrice: string, lineTotal: string}> $lines */
    public function __construct(
        public string $businessName,
        public string $businessAddress,
        public string $businessPhone,
        public string $businessEmail,
        public string $footer,
        public string $reference,
        public int $revision,
        public string $sentDate,
        public string $customerName,
        public ?string $customerPhone,
        public array $lines,
        public string $subtotal,
        public string $delivery,
        public string $discount,
        public string $total,
        public ?string $requiredAdvance,
        public ?string $exclusions,
        public ?string $validUntil,
        public ?string $statusNotice,
        public ?string $whatsAppUrl,
    ) {}
}
