<?php

namespace App\Actions\Stock;

use App\Domain\Stock\StockLimitExceeded;
use App\Enums\StockReason;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReserveStock
{
    public function __construct(private StockLedger $ledger) {}

    public function handle(Variant $variant, int $qty, StockReason $reason, Model $source, ?User $actor = null, ?string $overrideReason = null, ?Location $location = null): ?StockMovement
    {
        return $this->handleAggregated($variant, [$qty], $reason, $source, $actor, $overrideReason, $location);
    }

    /** @param list<int> $quantities */
    public function handleAggregated(Variant $variant, array $quantities, StockReason $reason, Model $source, ?User $actor = null, ?string $overrideReason = null, ?Location $location = null): ?StockMovement
    {
        if ($quantities === []) {
            throw new InvalidArgumentException('At least one quantity is required.');
        }
        $qty = 0;
        foreach ($quantities as $quantity) {
            if ($quantity < 1 || $quantity > 1000000) {
                throw new InvalidArgumentException('Quantity must be between 1 and 1,000,000.');
            }
            if ($quantity > StockLedger::MaxQty - $qty) {
                throw new StockLimitExceeded('That stock change exceeds the supported quantity range.');
            }
            $qty += $quantity;
        }
        if (! $variant->track_stock) {
            return null;
        }
        $overrideReason = trim($overrideReason ?? '');
        $overrideReason = $overrideReason === '' ? null : $overrideReason;

        return DB::transaction(fn (): StockMovement => $this->ledger->apply($variant, $location ?? Location::default(), -$qty, $reason, $source, $actor, $overrideReason, $overrideReason !== null));
    }
}
