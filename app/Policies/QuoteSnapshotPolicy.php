<?php

namespace App\Policies;

use App\Models\QuoteSnapshot;

class QuoteSnapshotPolicy extends ModelPolicy
{
    protected const string Model = QuoteSnapshot::class;
}
