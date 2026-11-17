<?php

namespace App\Domain\Customers;

final class Balance
{
    public static function owed(int $totalCents, int $paidCents, int $returnedCents = 0): int
    {
        return max(0, $totalCents - $returnedCents - $paidCents);
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return ['confirmed', 'processing', 'ready_for_collection', 'dispatched', 'completed'];
    }
}
