<?php

namespace App\Actions\Purchasing;

use App\Domain\Purchasing\ReceiptTotals;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveGoodsReceiptLine
{
    public function __construct(private LockDraftGoodsReceipt $lockDraft, private RecalculateReceiptTotal $totals) {}

    public function handle(GoodsReceipt $receipt, ?GoodsReceiptLine $line, int $variantId, int $qty, int $unitCostCents, User $actor): GoodsReceiptLine
    {
        return DB::transaction(function () use ($receipt, $line, $variantId, $qty, $unitCostCents, $actor): GoodsReceiptLine {
            $locked = $this->lockDraft->handle($receipt, $actor);
            $line = $line === null ? new GoodsReceiptLine : $locked->lines()->findOrFail($line->id);
            if ($qty < 1 || $qty > 1000000) {
                throw ValidationException::withMessages(['qty' => 'Enter a quantity between 1 and 1,000,000.']);
            }
            if ($unitCostCents < 0 || $unitCostCents > 10000000000) {
                throw ValidationException::withMessages(['unit_cost_cents' => 'Enter a cost between Rs 0.00 and Rs 100,000,000.00.']);
            }
            $variant = Variant::query()->sharedLock()->find($variantId);
            if ($variant === null || ! $variant->is_active || ! $variant->track_stock) {
                throw ValidationException::withMessages(['variant_id' => 'Choose an active item that tracks stock.']);
            }
            if ($locked->lines()->where('variant_id', $variantId)->when($line->exists, fn (Builder $query): Builder => $query->where('id', '<>', $line->id))->exists()) {
                throw ValidationException::withMessages(['variant_id' => "SKU {$variant->sku} is already on this receipt — edit that line"]);
            }
            $line->fill([
                'variant_id' => $variantId, 'qty' => $qty, 'unit_cost_cents' => $unitCostCents,
                'line_total_cents' => ReceiptTotals::lineTotal($qty, $unitCostCents),
                'sort_order' => $line->exists ? $line->sort_order : (int) $locked->lines()->max('sort_order') + 1,
            ]);
            $locked->lines()->save($line);
            $this->totals->handle($locked);

            return $line->refresh();
        });
    }
}
