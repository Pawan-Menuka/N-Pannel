<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;

final readonly class IssuedInvoice
{
    public function __construct(
        public Invoice $invoice,
        public string $token,
        public string $url,
        public string $whatsAppText,
        public ?string $whatsAppUrl,
    ) {}
}
