<?php

namespace App\Actions\Invoices;

use App\Actions\Orders\RecordOrderEvent;
use App\Enums\DocumentStatus;
use App\Enums\OrderEventType;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class VoidInvoice
{
    public function __construct(private RecordOrderEvent $events) {}

    public function handle(Invoice $invoice, string $reason, User $by): Invoice
    {
        $invoice = Invoice::query()->findOrFail($invoice->id);
        $reason = trim($reason);
        $this->validate($invoice, $reason);
        Gate::forUser($by)->authorize('void', $invoice);

        return DB::transaction(function () use ($invoice, $reason, $by): Invoice {
            $order = Order::query()->lockForUpdate()->findOrFail($invoice->order_id);
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->validate($locked, $reason);
            $locked->update(['status' => DocumentStatus::Void, 'voided_at' => now(), 'void_reason' => $reason, 'token_hash' => null]);
            $order->update(['tax_cents' => 0]);
            $this->events->handle($order, OrderEventType::Note, note: mb_substr('Invoice '.$locked->number.' voided: '.$reason, 0, 255), by: $by);

            return $locked;
        });
    }

    private function validate(Invoice $invoice, string $reason): void
    {
        if ($invoice->creditNotes()->exists()) {
            throw ValidationException::withMessages(['invoice' => 'This invoice has credit notes and cannot be voided.']);
        }
        if ($invoice->status !== DocumentStatus::Issued) {
            throw ValidationException::withMessages(['status' => 'This invoice is already void.']);
        }
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'A void reason of at most 255 characters is required.']);
        }
    }
}
