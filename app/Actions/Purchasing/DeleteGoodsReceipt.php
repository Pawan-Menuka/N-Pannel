<?php

namespace App\Actions\Purchasing;

use App\Models\GoodsReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteGoodsReceipt
{
    public function __construct(private LockDraftGoodsReceipt $lockDraft) {}

    public function handle(GoodsReceipt $receipt, User $actor): bool
    {
        return DB::transaction(fn (): bool => (bool) $this->lockDraft->handle($receipt, $actor, 'delete')->delete());
    }
}
