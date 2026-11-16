<?php

namespace App\Policies;

use App\Models\SalesReturnLine;

class SalesReturnLinePolicy extends ModelPolicy
{
    protected const string Model = SalesReturnLine::class;
}
