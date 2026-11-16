<?php

namespace App\Policies;

use App\Models\Category;

class CategoryPolicy extends ModelPolicy
{
    protected const string Model = Category::class;
}
