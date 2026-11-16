<?php

namespace App\Domain\Stock;

final class StockLevelStatus
{
    public static function isLow(int $qty, ?int $reorderLevel): bool
    {
        return $reorderLevel !== null && $qty <= $reorderLevel;
    }

    public static function isOut(int $qty): bool
    {
        return $qty <= 0;
    }
}
