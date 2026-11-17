<?php

namespace App\Domain\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;

final class PaymentStates
{
    public static function derive(int $effectiveTotalCents, int $netPaidCents, int $refundedCents, ?int $originalTotalCents = null): PaymentState
    {
        if ($netPaidCents <= 0 && $refundedCents > 0) {
            return PaymentState::Refunded;
        }
        if (($originalTotalCents ?? $effectiveTotalCents) > 0 && $netPaidCents === 0 && $refundedCents === 0) {
            return PaymentState::Unpaid;
        }
        if ($netPaidCents >= $effectiveTotalCents) {
            return PaymentState::Paid;
        }
        if ($netPaidCents <= 0) {
            return PaymentState::Unpaid;
        }

        return PaymentState::PartiallyPaid;
    }

    public static function balance(int $effectiveTotalCents, int $netPaidCents, OrderStatus $status): int
    {
        if (in_array($status, [OrderStatus::Cancelled, OrderStatus::Expired], true) || $netPaidCents >= $effectiveTotalCents) {
            return 0;
        }
        if ($netPaidCents < 0 && $effectiveTotalCents > PHP_INT_MAX + $netPaidCents) {
            throw new AmountTooLarge('The order balance is too large.');
        }

        return $effectiveTotalCents - $netPaidCents;
    }
}
