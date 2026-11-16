<?php

namespace App\Policies;

use App\Models\StockMovement;

class StockMovementPolicy extends ModelPolicy
{
    protected const string Model = StockMovement::class;
}
