<?php

namespace App\Policies;

use App\Models\OrderLine;

class OrderLinePolicy extends ModelPolicy
{
    protected const string Model = OrderLine::class;
}
