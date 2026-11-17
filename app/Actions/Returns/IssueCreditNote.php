<?php

namespace App\Actions\Returns;

use App\Actions\Documents\NextDocumentNumber;
use App\Domain\Invoice\Tax;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Internal issuance step: the caller must hold the order lock in the document transaction. */
final class IssueCreditNote
{
    public function __construct(private NextDocumentNumber $numbers) {}

    public function handle(Invoice $invoice, SalesReturn $salesReturn, User $by): CreditNote
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Issuing a credit note requires the order transaction.');
        }
        if ($invoice->status !== DocumentStatus::Issued || $invoice->order_id !== $salesReturn->order_id) {
            throw ValidationException::withMessages(['invoice' => 'The return must belong to the active invoice order.']);
        }
        Gate::forUser($by)->authorize('create', CreditNote::class);
        $existing = $salesReturn->creditNote()->first();
        if ($existing !== null) {
            if ($existing->invoice_id !== $invoice->id) {
                throw ValidationException::withMessages(['sales_return' => 'This return already has a credit note against another invoice.']);
            }

            return $existing;
        }
        $lines = $salesReturn->lines()->with('orderLine')->orderBy('id')->get()->map(function (SalesReturnLine $line): array {
            $orderLine = $line->orderLine;
            if ($orderLine === null) {
                throw ValidationException::withMessages(['lines' => 'A returned order line no longer exists.']);
            }

            return [
                'sku' => $orderLine->sku, 'description' => $orderLine->description, 'sale_unit' => $orderLine->sale_unit?->value,
                'qty' => $line->qty, 'unit_price_cents' => $line->unit_price_cents, 'line_total_cents' => $line->line_total_cents,
            ];
        })->all();
        $issuedOn = CarbonImmutable::today(config('app.timezone'));

        return CreditNote::query()->create([
            'number' => $this->numbers->handle(DocumentKind::CreditNote, $issuedOn),
            'order_id' => $salesReturn->order_id, 'invoice_id' => $invoice->id, 'sales_return_id' => $salesReturn->id,
            'customer_id' => $invoice->customer_id, 'customer_snapshot' => $invoice->customer_snapshot,
            'lines' => $lines, 'total_cents' => $salesReturn->total_cents,
            'tax_cents' => $invoice->vat_rate_bp === null ? 0 : Tax::inclusive($salesReturn->total_cents, $invoice->vat_rate_bp),
            'vat_rate_bp' => $invoice->vat_rate_bp, 'seller_vat_number' => $invoice->seller_vat_number,
            'issued_on' => $issuedOn, 'issued_by_id' => $by->id,
        ]);
    }
}
