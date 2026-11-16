<?php

namespace App\Policies;

use App\Models\SalesReturn;

class SalesReturnPolicy extends ModelPolicy
{
    protected const string Model = SalesReturn::class;
}
