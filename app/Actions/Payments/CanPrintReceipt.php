<?php

namespace App\Actions\Payments;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Payment;

final class CanPrintReceipt
{
    public static function check(Payment $payment): bool
    {
        return $payment->kind === PaymentKind::Payment && $payment->status === PaymentStatus::Confirmed && trim($payment->receipt_number ?? '') !== '';
    }
}
