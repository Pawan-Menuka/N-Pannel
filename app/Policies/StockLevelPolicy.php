<?php

namespace App\Policies;

use App\Models\StockLevel;

class StockLevelPolicy extends ModelPolicy
{
    protected const string Model = StockLevel::class;
}
