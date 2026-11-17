<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\RoleMatrix;

class PaymentPolicy extends ModelPolicy
{
    protected const string Model = Payment::class;

    public function refund(User $user): bool
    {
        return RoleMatrix::allows($user, Payment::class, 'refund');
    }
}
