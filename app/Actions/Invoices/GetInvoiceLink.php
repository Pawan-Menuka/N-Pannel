<?php

namespace App\Actions\Invoices;

use App\Enums\DocumentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class GetInvoiceLink
{
    public function __construct(private RotateInvoiceToken $tokens, private BuildInvoiceLink $links) {}

    public function handle(Invoice $invoice, User $by): IssuedInvoice
    {
        $invoice = Invoice::query()->findOrFail($invoice->id);
        $this->validate($invoice);
        Gate::forUser($by)->authorize('view', $invoice);

        return DB::transaction(function () use ($invoice): IssuedInvoice {
            $order = Order::query()->lockForUpdate()->findOrFail($invoice->order_id);
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->validate($locked);

            return $this->links->handle($locked, $order, $this->tokens->handle($locked));
        });
    }

    private function validate(Invoice $invoice): void
    {
        if ($invoice->status !== DocumentStatus::Issued) {
            throw ValidationException::withMessages(['status' => 'This invoice is void.']);
        }
    }
}
