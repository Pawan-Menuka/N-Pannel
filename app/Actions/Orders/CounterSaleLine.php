<?php

namespace App\Actions\Orders;

use App\Enums\OrderLineKind;

final readonly class CounterSaleLine
{
    public function __construct(
        public OrderLineKind $kind,
        public ?int $variantId,
        public ?string $description,
        public int $qty,
        public ?int $unitPriceCents,
    ) {}
}
