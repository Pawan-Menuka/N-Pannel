<?php

namespace App\Policies;

use App\Models\Variant;

class VariantPolicy extends ModelPolicy
{
    protected const string Model = Variant::class;
}
