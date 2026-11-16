<?php

namespace App\Policies;

use App\Models\StocktakeLine;

class StocktakeLinePolicy extends ModelPolicy
{
    protected const string Model = StocktakeLine::class;
}
