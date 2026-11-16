<?php

namespace App\Actions\Purchasing;

use App\Actions\Stock\StockLedger;
use App\Domain\Stock\StockLimitExceeded;
use App\Enums\GoodsReceiptStatus;
use App\Enums\StockReason;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Lock variants and stock levels in ascending variant_id order.
 * FOR NO KEY UPDATE serializes costs without blocking stock movement foreign key checks.
 */
final class VoidGoodsReceipt
{
    public function __construct(private StockLedger $ledger) {}

    public function handle(GoodsReceipt $receipt, User $actor, string $reason, bool $override = false): GoodsReceipt
    {
        try {
            return DB::transaction(function () use ($receipt, $actor, $reason, $override): GoodsReceipt {
                $locked = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
                if ($locked->status !== GoodsReceiptStatus::Posted) {
                    throw ValidationException::withMessages(['receipt' => 'Only posted goods received notes can be voided.']);
                }
                Gate::forUser($actor)->authorize('void', $locked);
                if ($override) {
                    Gate::forUser($actor)->authorize('voidOverride', $locked);
                }
                $reason = trim($reason);
                if ($reason === '' || mb_strlen($reason) > 255) {
                    throw ValidationException::withMessages(['reason' => 'A reason of at most 255 characters is required.']);
                }
                $lines = $locked->lines()->orderBy('variant_id')->get();
                $variants = Variant::query()->whereIn('id', $lines->pluck('variant_id'))->orderBy('id')->lock('for no key update')->get()->keyBy('id');
                $shortages = [];
                $lineOverrides = [];
                foreach ($lines as $line) {
                    StockLevel::query()->insertOrIgnore([
                        'variant_id' => $line->variant_id, 'location_id' => $locked->location_id,
                        'qty' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $level = StockLevel::query()->where('variant_id', $line->variant_id)->where('location_id', $locked->location_id)->lockForUpdate()->firstOrFail();
                    $lineOverrides[$line->id] = $override && $level->qty < $line->qty;
                    if (! $override && $level->qty < $line->qty) {
                        $shortages[] = ($variants->get($line->variant_id)->sku ?? $line->variant_id).": received {$line->qty}, in stock {$level->qty}";
                    }
                }
                if ($shortages !== []) {
                    throw ValidationException::withMessages(['receipt' => 'Cannot void this receipt: '.implode('; ', $shortages)]);
                }
                foreach ($lines as $line) {
                    $variant = $variants->get($line->variant_id);
                    if ($variant === null) {
                        throw ValidationException::withMessages(['receipt' => 'A receipt item no longer exists.']);
                    }
                    $this->ledger->apply($variant, $locked->location, -$line->qty, StockReason::GoodsReceivedVoid, $locked, $actor, $reason, override: $lineOverrides[$line->id]);
                    $previous = GoodsReceiptLine::query()->select('goods_receipt_lines.*')
                        ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')
                        ->join('stock_movements', 'stock_movements.source_id', '=', 'goods_receipts.id')
                        ->where('stock_movements.source_type', $locked->getMorphClass())
                        ->where('stock_movements.reason', StockReason::GoodsReceived->value)
                        ->where('stock_movements.variant_id', $line->variant_id)
                        ->where('goods_receipt_lines.variant_id', $line->variant_id)
                        ->where('goods_receipts.status', GoodsReceiptStatus::Posted->value)
                        ->where('goods_receipts.id', '<>', $locked->id)
                        ->orderByDesc('stock_movements.id')->first();
                    $variant->update(['last_cost_cents' => $previous->unit_cost_cents ?? $line->previous_last_cost_cents]);
                }
                $locked->update(['status' => GoodsReceiptStatus::Void, 'voided_by_id' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason]);

                return $locked->refresh();
            });
        } catch (StockLimitExceeded) {
            throw ValidationException::withMessages(['receipt' => 'Voiding would exceed the supported stock quantity range.']);
        }
    }
}
