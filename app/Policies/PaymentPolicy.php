<?php

namespace App\Policies;

use App\Models\Payment;

class PaymentPolicy extends ModelPolicy
{
    protected const string Model = Payment::class;
}
