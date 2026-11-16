<?php

namespace App\Actions\Stock;

use App\Models\Stocktake;
use Illuminate\Database\Eloquent\Builder;

final class PreviewStocktake
{
    /** @return array{changes: int, uncounted: int} */
    public function handle(Stocktake $stocktake): array
    {
        return [
            'changes' => $stocktake->lines()->whereNotNull('counted_qty')->whereHas('variant', fn (Builder $query): Builder => $query->where('track_stock', true))
                ->whereRaw('counted_qty <> coalesce((select qty from stock_levels where stock_levels.variant_id = stocktake_lines.variant_id and stock_levels.location_id = ?), 0)', [$stocktake->location_id])->count(),
            'uncounted' => $stocktake->lines()->whereNull('counted_qty')->count(),
        ];
    }
}
