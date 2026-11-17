<?php

namespace App\Domain\Returns;

use App\Domain\Order\AmountTooLarge;
use App\Domain\Order\InvalidOrderTotals;
use App\Domain\Order\Totals;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;

final class ReturnCalculator
{
    /**
     * Floor the proportional unit value without multiplying two potentially large money amounts.
     * Binary long division keeps a quotient and remainder; subtracting before adding avoids overflow.
     */
    public static function discountedUnitValue(int $unitPriceCents, int $subtotalCents, int $discountCents): int
    {
        if ($unitPriceCents < 0 || $subtotalCents < 0 || $discountCents < 0) {
            throw new InvalidOrderTotals('Amounts must not be negative.');
        }
        if ($subtotalCents === 0) {
            return 0;
        }
        if ($discountCents === 0) {
            return $unitPriceCents;
        }
        $numerator = max(0, $subtotalCents - $discountCents);
        $quotient = 0;
        $remainder = 0;
        foreach (str_split(decbin($unitPriceCents)) as $bit) {
            $quotient *= 2;
            if ($remainder >= $subtotalCents - $remainder) {
                $remainder -= $subtotalCents - $remainder;
                $quotient++;
            } else {
                $remainder += $remainder;
            }
            if ($bit === '1') {
                if ($remainder >= $subtotalCents - $numerator) {
                    $remainder -= $subtotalCents - $numerator;
                    $quotient++;
                } else {
                    $remainder += $numerator;
                }
            }
        }

        return $quotient;
    }

    public static function acceptsStatus(OrderStatus $status): bool
    {
        return in_array($status, [OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched, OrderStatus::Completed], true);
    }

    /**
     * Only product lines are supplied in $orderLines; anything else is unknown.
     *
     * @param  list<array{id: int, qty: int, unit_price_cents: int, already_returned_qty: int}>  $orderLines
     * @param  list<array{order_line_id: int, qty: int, unit_value_cents: int, restock: bool}>  $requested
     * @return array{lines: list<array{order_line_id: int, qty: int, unit_value_cents: int, restock: bool, line_total_cents: int}>, total_cents: int, max_refundable_cents: int, errors: list<string>}
     */
    public static function calculate(array $orderLines, array $requested, int $totalCents, int $returnedCents, int $netPaidCents, ?int $subtotalCents = null, int $discountCents = 0): array
    {
        $available = [];
        foreach ($orderLines as $line) {
            $available[$line['id']] = $line;
        }
        $errors = [];
        $lines = [];
        $seen = [];
        $total = 0;
        if ($requested === []) {
            $errors[] = 'Choose at least one product to return.';
        }
        foreach ($requested as $input) {
            $id = $input['order_line_id'];
            $line = $available[$id] ?? null;
            if ($line === null) {
                $errors[] = 'Choose a product line belonging to this order.';

                continue;
            }
            $remaining = max(0, $line['qty'] - $line['already_returned_qty'] - ($seen[$id] ?? 0));
            if ($input['qty'] < 1) {
                $errors[] = 'Quantity must be at least one.';

                continue;
            }
            if ($input['qty'] > $remaining) {
                $errors[] = 'Only '.$remaining.' items remain returnable on line '.$id.'.';

                continue;
            }
            if ($input['unit_value_cents'] < 0) {
                $errors[] = 'The return unit value cannot be negative.';

                continue;
            }
            $unitCap = $subtotalCents === null ? $line['unit_price_cents'] : self::discountedUnitValue($line['unit_price_cents'], $subtotalCents, $discountCents);
            if ($input['unit_value_cents'] > $unitCap) {
                $errors[] = 'The return unit value cannot exceed the sale price.';

                continue;
            }
            $seen[$id] = ($seen[$id] ?? 0) + $input['qty'];
            try {
                $amount = Totals::lineTotal(OrderLineKind::Product, $input['qty'], $input['unit_value_cents']);
                $total = Totals::add($total, $amount);
                $lines[] = [...$input, 'line_total_cents' => $amount];
            } catch (AmountTooLarge|InvalidOrderTotals $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        if ($returnedCents < 0 || $returnedCents > $totalCents || $total > $totalCents - $returnedCents) {
            $errors[] = 'The cumulative returned value cannot exceed the order total.';
        }
        if ($total === 0 && $errors === []) {
            $errors[] = 'The return total must be more than Rs 0.00.';
        }
        if ($errors !== []) {
            return ['lines' => [], 'total_cents' => 0, 'max_refundable_cents' => 0, 'errors' => $errors];
        }
        $effectiveTotal = $totalCents - $returnedCents - $total;

        return ['lines' => $lines, 'total_cents' => $total, 'max_refundable_cents' => $netPaidCents > $effectiveTotal ? $netPaidCents - $effectiveTotal : 0, 'errors' => []];
    }
}
