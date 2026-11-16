<?php

namespace App\Domain\Stock;

final class StocktakeVariance
{
    public static function delta(int $counted, int $expected): int
    {
        if ($counted < 0) {
            throw new \InvalidArgumentException('Counted quantity cannot be negative.');
        }

        return $counted - $expected;
    }
}
