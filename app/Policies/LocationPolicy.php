<?php

namespace App\Policies;

use App\Models\Location;

class LocationPolicy extends ModelPolicy
{
    protected const string Model = Location::class;
}
