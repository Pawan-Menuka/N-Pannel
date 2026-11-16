<?php

namespace App\Domain\Customers;

final class Balance
{
    public static function owed(int $totalCents, int $paidCents): int
    {
        return max(0, $totalCents - $paidCents);
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return ['confirmed', 'processing', 'ready_for_collection', 'dispatched', 'completed'];
    }
}
