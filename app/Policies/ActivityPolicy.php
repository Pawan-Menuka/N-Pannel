<?php

namespace App\Policies;

use Spatie\Activitylog\Models\Activity;

class ActivityPolicy extends ModelPolicy
{
    protected const string Model = Activity::class;
}
