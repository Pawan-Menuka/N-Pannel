<?php

namespace App\Actions\Returns;

use App\Enums\PaymentMethod;

final readonly class ReturnRefundInput
{
    public function __construct(public PaymentMethod $method, public int $amountCents) {}
}
