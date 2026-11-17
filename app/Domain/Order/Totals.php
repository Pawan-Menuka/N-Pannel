<?php

namespace App\Domain\Order;

use App\Enums\OrderLineKind;

final class Totals
{
    public static function lineTotal(OrderLineKind $kind, int $qty, int $unitPriceCents): int
    {
        if ($qty < 1 || $qty > 1000000 || $unitPriceCents < 0 || $unitPriceCents > 10000000000) {
            throw new InvalidOrderTotals('Quantity must be 1 to 1,000,000 and unit price must be 0 to 10,000,000,000 cents.');
        }
        if ($unitPriceCents > intdiv(PHP_INT_MAX, $qty)) {
            throw new AmountTooLarge('The order amount is too large.');
        }
        $amount = $qty * $unitPriceCents;

        return $kind === OrderLineKind::Discount ? -$amount : $amount;
    }

    /** @param list<array{kind: OrderLineKind, qty: int, unitPriceCents: int}> $lines */
    public static function calculate(array $lines, ?int $requiredAdvanceCents = null): OrderTotals
    {
        $subtotal = 0;
        $delivery = 0;
        $discount = 0;
        foreach ($lines as $line) {
            $amount = self::lineTotal($line['kind'], $line['qty'], $line['unitPriceCents']);
            if ($line['kind'] === OrderLineKind::Discount) {
                $discount = self::add($discount, -$amount);
            } elseif ($line['kind'] === OrderLineKind::Delivery) {
                $delivery = self::add($delivery, $amount);
            } else {
                $subtotal = self::add($subtotal, $amount);
            }
        }
        $gross = self::add($subtotal, $delivery);
        if ($discount > $gross) {
            throw new InvalidOrderTotals('The discount is larger than the order.');
        }
        $total = $gross - $discount;
        if ($requiredAdvanceCents !== null && ($requiredAdvanceCents < 0 || $requiredAdvanceCents > $total)) {
            throw new InvalidOrderTotals('The required advance must be between zero and the order total.');
        }

        return new OrderTotals($subtotal, $delivery, $discount, $total, $requiredAdvanceCents);
    }

    /** Add nonnegative money amounts without allowing PHP to promote to float. */
    public static function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new InvalidOrderTotals('Amounts must not be negative.');
        }
        if ($right > PHP_INT_MAX - $left) {
            throw new AmountTooLarge('The order amount is too large.');
        }

        return $left + $right;
    }
}
