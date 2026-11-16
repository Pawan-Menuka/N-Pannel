<?php

namespace App\Actions\Purchasing;

use App\Models\GoodsReceiptLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteGoodsReceiptLine
{
    public function __construct(private LockDraftGoodsReceipt $lockDraft, private RecalculateReceiptTotal $totals) {}

    public function handle(GoodsReceiptLine $line, User $actor): bool
    {
        return DB::transaction(function () use ($line, $actor): bool {
            $locked = $this->lockDraft->handle($line->goodsReceipt, $actor);
            $deleted = (bool) $locked->lines()->findOrFail($line->id)->delete();
            $this->totals->handle($locked);

            return $deleted;
        });
    }
}
