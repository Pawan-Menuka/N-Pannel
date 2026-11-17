<?php

namespace App\Domain\Order;

final class QuoteValidity
{
    public static function isExpired(int $nowTimestamp, ?int $validUntilTimestamp): bool
    {
        return $validUntilTimestamp !== null && $nowTimestamp > $validUntilTimestamp;
    }
}
