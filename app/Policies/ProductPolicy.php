<?php

namespace App\Policies;

use App\Models\Product;

class ProductPolicy extends ModelPolicy
{
    protected const string Model = Product::class;
}
