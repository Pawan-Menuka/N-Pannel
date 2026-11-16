<?php

namespace App\Actions\Purchasing;

use App\Actions\Documents\NextDocumentNumber;
use App\Actions\Stock\StockLedger;
use App\Domain\Stock\StockLimitExceeded;
use App\Enums\DocumentKind;
use App\Enums\GoodsReceiptStatus;
use App\Enums\StockReason;
use App\Models\GoodsReceipt;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lock variants and stock levels in ascending variant_id order.
 * FOR NO KEY UPDATE serializes costs without blocking stock movement foreign key checks.
 */
final class PostGoodsReceipt
{
    public function __construct(private LockDraftGoodsReceipt $lockDraft, private StockLedger $ledger, private NextDocumentNumber $numbers, private RecalculateReceiptTotal $totals) {}

    public function handle(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        try {
            return DB::transaction(function () use ($receipt, $actor): GoodsReceipt {
                $locked = $this->lockDraft->handle($receipt, $actor, 'post');
                $supplier = $locked->supplier()->sharedLock()->firstOrFail();
                if (! $supplier->is_active) {
                    throw ValidationException::withMessages(['receipt' => "Supplier {$supplier->name} is inactive. Reactivate it or change the supplier before posting."]);
                }
                $lines = $locked->lines()->orderBy('variant_id')->get();
                if ($lines->isEmpty()) {
                    throw ValidationException::withMessages(['receipt' => 'Add at least one line before posting.']);
                }
                $variants = Variant::query()->whereIn('id', $lines->pluck('variant_id'))->orderBy('id')->lock('for no key update')->get()->keyBy('id');
                $invalid = [];
                foreach ($lines as $line) {
                    $variant = $variants->get($line->variant_id);
                    if ($variant === null || ! $variant->is_active || ! $variant->track_stock) {
                        $invalid[] = $variant->sku ?? (string) $line->variant_id;
                    }
                }
                if ($invalid !== []) {
                    throw ValidationException::withMessages(['receipt' => 'These items must be active and track stock: '.implode(', ', $invalid).'.']);
                }
                $this->totals->handle($locked);
                $number = $this->numbers->handle(DocumentKind::GoodsReceipt);
                foreach ($lines as $line) {
                    $variant = $variants->get($line->variant_id);
                    if ($variant === null) {
                        throw ValidationException::withMessages(['receipt' => 'A receipt item no longer exists.']);
                    }
                    $line->update(['previous_last_cost_cents' => $variant->last_cost_cents]);
                    $this->ledger->apply($variant, $locked->location, $line->qty, StockReason::GoodsReceived, $locked, $actor);
                    $variant->update(['last_cost_cents' => $line->unit_cost_cents]);
                }
                $locked->update(['number' => $number, 'status' => GoodsReceiptStatus::Posted, 'posted_by_id' => $actor->id, 'posted_at' => now()]);

                return $locked->refresh();
            });
        } catch (StockLimitExceeded) {
            throw ValidationException::withMessages(['receipt' => 'Posting would exceed the supported stock quantity range.']);
        }
    }
}
