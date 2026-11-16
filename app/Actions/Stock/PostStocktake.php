<?php

namespace App\Actions\Stock;

use App\Domain\Stock\StockLimitExceeded;
use App\Domain\Stock\StocktakeVariance;
use App\Enums\StockReason;
use App\Enums\StocktakeStatus;
use App\Models\StockLevel;
use App\Models\Stocktake;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Multi-row stock operations must lock stock_levels in ascending variant_id order.
 */
final class PostStocktake
{
    public function __construct(private StockLedger $ledger, private LockDraftStocktake $lockDraft) {}

    public function handle(Stocktake $stocktake, User $actor): Stocktake
    {
        try {
            return DB::transaction(function () use ($stocktake, $actor): Stocktake {
                $locked = $this->lockDraft->handle($stocktake, $actor);
                $lines = $locked->lines()->with('variant')->orderBy('variant_id')->get();
                if ($lines->whereNotNull('counted_qty')->isEmpty()) {
                    throw ValidationException::withMessages(['stocktake' => 'Enter at least one count.']);
                }
                foreach ($lines as $line) {
                    if ($line->counted_qty === null || ! $line->variant->track_stock) {
                        continue;
                    }
                    StockLevel::query()->insertOrIgnore([
                        'variant_id' => $line->variant_id, 'location_id' => $locked->location_id,
                        'qty' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $level = StockLevel::query()->where('variant_id', $line->variant_id)
                        ->where('location_id', $locked->location_id)->lockForUpdate()->firstOrFail();
                    $line->update(['expected_qty' => $level->qty]);
                    $delta = StocktakeVariance::delta($line->counted_qty, $level->qty);
                    if ($delta !== 0) {
                        $this->ledger->apply($line->variant, $locked->location, $delta, StockReason::Stocktake, $locked, $actor, guardNegative: false);
                    }
                }
                $locked->update(['status' => StocktakeStatus::Posted, 'posted_by_id' => $actor->id, 'posted_at' => now()]);

                return $locked->refresh();
            });
        } catch (StockLimitExceeded) {
            throw ValidationException::withMessages(['stocktake' => 'The stocktake variance exceeds the supported stock quantity range.']);
        }
    }
}
