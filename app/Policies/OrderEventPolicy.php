<?php

namespace App\Policies;

use App\Models\OrderEvent;

class OrderEventPolicy extends ModelPolicy
{
    protected const string Model = OrderEvent::class;
}
