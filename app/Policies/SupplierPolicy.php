<?php

namespace App\Policies;

use App\Models\Supplier;

class SupplierPolicy extends ModelPolicy
{
    protected const string Model = Supplier::class;
}
