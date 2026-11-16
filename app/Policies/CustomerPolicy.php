<?php

namespace App\Policies;

use App\Models\Customer;

class CustomerPolicy extends ModelPolicy
{
    protected const string Model = Customer::class;
}
