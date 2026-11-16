<?php

namespace App\Policies;

use App\Models\StockLevel;
use App\Models\User;
use App\Policies\Concerns\RoleMatrix;

class StockLevelPolicy extends ModelPolicy
{
    protected const string Model = StockLevel::class;

    public function adjust(User $user): bool
    {
        return RoleMatrix::allows($user, StockLevel::class, 'adjust');
    }
}
