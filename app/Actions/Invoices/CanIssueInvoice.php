<?php

namespace App\Actions\Invoices;

use App\Domain\Invoice\InvoiceEligibility;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;

final class CanIssueInvoice
{
    public static function check(Order $order, User $by): bool
    {
        return $by->can('create', Invoice::class) && InvoiceEligibility::acceptsStatus($order->status) && $order->activeInvoice() === null;
    }
}
