<?php

namespace App\Policies;

use App\Models\District;

class DistrictPolicy extends ModelPolicy
{
    protected const string Model = District::class;
}
