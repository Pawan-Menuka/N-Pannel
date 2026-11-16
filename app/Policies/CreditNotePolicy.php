<?php

namespace App\Policies;

use App\Models\CreditNote;

class CreditNotePolicy extends ModelPolicy
{
    protected const string Model = CreditNote::class;
}
