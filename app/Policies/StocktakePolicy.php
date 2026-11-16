<?php

namespace App\Policies;

use App\Models\Stocktake;

class StocktakePolicy extends ModelPolicy
{
    protected const string Model = Stocktake::class;
}
