<?php

namespace App\Policies;

use App\Models\Invoice;

class InvoicePolicy extends ModelPolicy
{
    protected const string Model = Invoice::class;
}
