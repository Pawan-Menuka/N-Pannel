<?php

namespace App\Actions\Stock;

use App\Models\Stocktake;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

final class AddAllTrackedItems
{
    public function __construct(private LockDraftStocktake $lockDraft) {}

    public function handle(Stocktake $stocktake, User $actor): int
    {
        return DB::transaction(function () use ($stocktake, $actor): int {
            $locked = $this->lockDraft->handle($stocktake, $actor);
            $variants = Variant::query()->where('track_stock', true)->where('is_active', true)
                ->whereNotIn('id', $locked->lines()->select('variant_id'))->orderBy('id')->get();
            foreach ($variants as $variant) {
                $locked->lines()->create(['variant_id' => $variant->id, 'counted_qty' => null]);
            }

            return $variants->count();
        });
    }
}
