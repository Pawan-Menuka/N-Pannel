<?php

namespace App\Domain\Invoice;

use InvalidArgumentException;
use OverflowException;

final class Tax
{
    public static function inclusive(int $totalCents, int $rateBp): int
    {
        if ($totalCents < 0 || $rateBp < 1 || $rateBp > 10000) {
            throw new InvalidArgumentException('The total must be nonnegative and the VAT rate must be between 1 and 10,000 basis points.');
        }
        if ($totalCents > intdiv(PHP_INT_MAX, $rateBp)) {
            throw new OverflowException('The VAT calculation is too large.');
        }
        $numerator = $totalCents * $rateBp;
        $denominator = 10000 + $rateBp;

        return intdiv($numerator, $denominator) + ($numerator % $denominator >= intdiv($denominator + 1, 2) ? 1 : 0);
    }
}
