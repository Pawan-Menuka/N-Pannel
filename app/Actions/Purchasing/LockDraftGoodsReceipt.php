<?php

namespace App\Actions\Purchasing;

use App\Enums\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class LockDraftGoodsReceipt
{
    public function handle(GoodsReceipt $receipt, User $actor, string $ability = 'update'): GoodsReceipt
    {
        $locked = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
        if ($locked->status !== GoodsReceiptStatus::Draft) {
            throw ValidationException::withMessages(['receipt' => 'Only draft goods received notes can be changed.']);
        }
        Gate::forUser($actor)->authorize($ability, $locked);

        return $locked;
    }
}
