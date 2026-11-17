<?php

namespace App\Actions\Orders;

use App\Enums\PaymentMethod;

final readonly class CounterSalePayment
{
    public function __construct(
        public PaymentMethod $method,
        public int $amountCents,
        public ?string $reference,
        public ?string $note,
    ) {}
}
