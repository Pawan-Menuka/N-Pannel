<?php

namespace App\Domain\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;

final class PaymentStates
{
    public static function derive(int $totalCents, int $netPaidCents, int $refundedCents): PaymentState
    {
        if ($netPaidCents <= 0 && $refundedCents > 0) {
            return PaymentState::Refunded;
        }
        if ($netPaidCents >= $totalCents) {
            return PaymentState::Paid;
        }
        if ($netPaidCents <= 0) {
            return PaymentState::Unpaid;
        }

        return PaymentState::PartiallyPaid;
    }

    public static function balance(int $totalCents, int $netPaidCents, OrderStatus $status): int
    {
        if (in_array($status, [OrderStatus::Cancelled, OrderStatus::Expired], true) || $netPaidCents >= $totalCents) {
            return 0;
        }
        if ($netPaidCents < 0 && $totalCents > PHP_INT_MAX + $netPaidCents) {
            throw new AmountTooLarge('The order balance is too large.');
        }

        return $totalCents - $netPaidCents;
    }
}
