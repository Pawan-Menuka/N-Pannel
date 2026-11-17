<?php

namespace App\Domain\Order;

final readonly class OrderTotals
{
    public function __construct(
        public int $subtotalCents,
        public int $deliveryCents,
        public int $discountCents,
        public int $totalCents,
        public ?int $requiredAdvanceCents,
    ) {}
}
