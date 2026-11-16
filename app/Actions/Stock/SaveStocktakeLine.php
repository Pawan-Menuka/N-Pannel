<?php

namespace App\Actions\Stock;

use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveStocktakeLine
{
    public function __construct(private LockDraftStocktake $lockDraft) {}

    public function handle(Stocktake $stocktake, Variant $variant, int $countedQty, User $actor): StocktakeLine
    {
        return DB::transaction(function () use ($stocktake, $variant, $countedQty, $actor): StocktakeLine {
            $locked = $this->lockDraft->handle($stocktake, $actor);
            if ($countedQty < 0 || $countedQty > 1000000) {
                throw ValidationException::withMessages(['counted_qty' => 'Enter a quantity between 0 and 1,000,000.']);
            }
            if (! Variant::query()->findOrFail($variant->id)->track_stock) {
                throw ValidationException::withMessages(['variant_id' => "This item doesn't track stock."]);
            }

            return StocktakeLine::query()->updateOrCreate(
                ['stocktake_id' => $locked->id, 'variant_id' => $variant->id],
                ['counted_qty' => $countedQty, 'expected_qty' => null],
            );
        });
    }
}
