<?php

namespace App\Actions\Orders;

final readonly class QuoteLink
{
    public function __construct(
        public string $url,
        public int $revision,
        public string $whatsAppText,
        public ?string $whatsAppUrl,
    ) {}
}
