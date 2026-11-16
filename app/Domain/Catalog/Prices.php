<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

final class Prices
{
    public static function ensureRetail(int $priceCents): void
    {
        if ($priceCents <= 0) {
            throw new InvalidArgumentException('Price must be greater than zero');
        }
    }

    public static function ensureTrade(int $priceCents, int $tradePriceCents): void
    {
        if ($tradePriceCents > $priceCents) {
            throw new InvalidArgumentException('Trade price must not exceed price');
        }
    }
}
