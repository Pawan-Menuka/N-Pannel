<?php

namespace App\Domain\Purchasing;

use InvalidArgumentException;

final class ReceiptTotals
{
    public static function lineTotal(int $qty, int $unitCostCents): int
    {
        if ($qty < 0 || $unitCostCents < 0) {
            throw new InvalidArgumentException('Quantity and cost must not be negative.');
        }
        if ($qty !== 0 && $unitCostCents > intdiv(PHP_INT_MAX, $qty)) {
            throw new AmountTooLarge('The receipt amount is too large.');
        }

        return $qty * $unitCostCents;
    }

    /** @param list<int> $amounts */
    public static function sum(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException('Amounts must not be negative.');
            }
            if ($amount > PHP_INT_MAX - $total) {
                throw new AmountTooLarge('The receipt amount is too large.');
            }
            $total += $amount;
        }

        return $total;
    }
}
