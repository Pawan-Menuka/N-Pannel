<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\RoleMatrix;

class InvoicePolicy extends ModelPolicy
{
    protected const string Model = Invoice::class;

    public function void(User $user, Invoice $invoice): bool
    {
        return RoleMatrix::allows($user, Invoice::class, 'void', $invoice);
    }
}
