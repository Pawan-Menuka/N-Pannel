<?php

namespace App\Domain\Invoice;

use App\Enums\OrderStatus;

final class InvoiceEligibility
{
    public static function acceptsStatus(OrderStatus $status): bool
    {
        return in_array($status, [OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched, OrderStatus::Completed], true);
    }
}
