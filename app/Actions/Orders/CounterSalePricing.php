<?php

namespace App\Actions\Orders;

use App\Enums\PriceTier;
use App\Models\Variant;

final class CounterSalePricing
{
    public function handle(Variant $variant, PriceTier $tier): int
    {
        return $tier === PriceTier::Trade && $variant->trade_price_cents !== null
            ? $variant->trade_price_cents : $variant->price_cents;
    }

    public function replacementPrice(Variant $variant, int $currentPriceCents, PriceTier $oldTier, PriceTier $newTier): ?int
    {
        return $currentPriceCents === $this->handle($variant, $oldTier) ? $this->handle($variant, $newTier) : null;
    }
}
