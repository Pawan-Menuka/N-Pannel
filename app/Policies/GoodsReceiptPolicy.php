<?php

namespace App\Policies;

use App\Enums\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Models\User;
use App\Policies\Concerns\RoleMatrix;

class GoodsReceiptPolicy extends ModelPolicy
{
    protected const string Model = GoodsReceipt::class;

    public function post(User $user, GoodsReceipt $record): bool
    {
        return RoleMatrix::allows($user, self::Model, 'post', $record);
    }

    public function void(User $user, GoodsReceipt $record): bool
    {
        return $record->status === GoodsReceiptStatus::Posted && RoleMatrix::allows($user, self::Model, 'void', $record);
    }

    public function voidOverride(User $user, GoodsReceipt $record): bool
    {
        return RoleMatrix::allows($user, self::Model, 'voidOverride', $record);
    }
}
