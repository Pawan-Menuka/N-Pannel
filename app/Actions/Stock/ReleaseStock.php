<?php

namespace App\Actions\Stock;

use App\Enums\StockReason;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReleaseStock
{
    public function __construct(private StockLedger $ledger) {}

    public function handle(Variant $variant, int $qty, StockReason $reason, Model $source, ?User $actor = null, ?string $note = null, ?Location $location = null): ?StockMovement
    {
        if ($qty < 1 || $qty > 1000000) {
            throw new InvalidArgumentException('Quantity must be between 1 and 1,000,000.');
        }
        if (! $variant->track_stock) {
            return null;
        }

        return DB::transaction(fn (): StockMovement => $this->ledger->apply($variant, $location ?? Location::default(), $qty, $reason, $source, $actor, $note));
    }
}
