<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use LogicException;

final class RotateInvoiceToken
{
    public function handle(Invoice $invoice): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Rotating an invoice token requires a database transaction and order lock.');
        }
        $plain = bin2hex(random_bytes(32));
        $invoice->update(['token_hash' => hash('sha256', $plain)]);

        return $plain;
    }
}
