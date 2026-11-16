<?php

namespace App\Actions\Purchasing;

use App\Models\GoodsReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateGoodsReceiptDetails
{
    public function __construct(private LockDraftGoodsReceipt $lockDraft, private ReceiptDetails $details) {}

    public function handle(GoodsReceipt $receipt, User $actor, int $supplierId, string $receivedOn, ?string $supplierReference = null, ?string $note = null): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $actor, $supplierId, $receivedOn, $supplierReference, $note): GoodsReceipt {
            $locked = $this->lockDraft->handle($receipt, $actor);
            $locked->update($this->details->validate($supplierId, $receivedOn, $supplierReference, $note));

            return $locked->refresh();
        });
    }
}
