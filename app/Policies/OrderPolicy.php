<?php

namespace App\Policies;

use App\Models\Order;

class OrderPolicy extends ModelPolicy
{
    protected const string Model = Order::class;
}
