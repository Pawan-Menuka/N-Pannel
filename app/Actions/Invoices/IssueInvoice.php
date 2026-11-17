<?php

namespace App\Actions\Invoices;

use App\Actions\Documents\NextDocumentNumber;
use App\Actions\Orders\RecordOrderEvent;
use App\Domain\Invoice\InvoiceEligibility;
use App\Domain\Invoice\Tax;
use App\Domain\Invoice\TaxRegistration;
use App\Enums\DocumentKind;
use App\Enums\OrderEventType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Settings\DocumentSettings;
use App\Settings\TaxSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Lock order: customer (shared) -> order -> document sequence. */
final class IssueInvoice
{
    public function __construct(
        private NextDocumentNumber $numbers,
        private RotateInvoiceToken $tokens,
        private RecordOrderEvent $events,
        private BuildInvoiceLink $links,
        private DocumentSettings $documents,
        private TaxSettings $tax,
    ) {}

    public function handle(Order $order, ?CarbonImmutable $dueOn, User $by): IssuedInvoice
    {
        $order = Order::query()->findOrFail($order->id);
        $this->validate($order);
        $this->dueDate($dueOn, CarbonImmutable::today(config('app.timezone')));
        Gate::forUser($by)->authorize('create', Invoice::class);

        return DB::transaction(function () use ($order, $dueOn, $by): IssuedInvoice {
            $customer = $order->customer_id === null ? null : Customer::query()->whereKey($order->customer_id)->sharedLock()->first();
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->validate($locked);
            if ($locked->customer_id !== $order->customer_id || ($locked->customer_id !== null && $customer === null)) {
                throw ValidationException::withMessages(['customer_id' => 'The order customer has changed. Reload the order and try again.']);
            }
            $issuedOn = CarbonImmutable::today(config('app.timezone'));
            $dueDate = $this->dueDate($dueOn, $issuedOn);
            $sellerVatNumber = trim($this->tax->vat_number ?? '');
            $rate = $this->tax->vat_rate_bp ?? 0;
            $isTaxInvoice = $this->isTaxInvoice();
            $taxCents = $isTaxInvoice ? Tax::inclusive($locked->total_cents, $rate) : 0;
            $invoice = Invoice::query()->create([
                'number' => $this->numbers->handle(DocumentKind::Invoice, $issuedOn),
                'order_id' => $locked->id, 'customer_id' => $locked->customer_id, 'locale' => $locked->locale,
                'customer_snapshot' => $this->snapshot($customer, $locked),
                'lines' => $locked->lines()->orderBy('sort_order')->orderBy('id')->get()->map(fn (OrderLine $line): array => [
                    'kind' => $line->kind->value, 'sku' => $line->sku, 'description' => $line->description,
                    'sale_unit' => $line->sale_unit?->value, 'qty' => $line->qty,
                    'unit_price_cents' => $line->unit_price_cents, 'line_total_cents' => $line->line_total_cents,
                    'sort_order' => $line->sort_order,
                ])->all(),
                'subtotal_cents' => $locked->subtotal_cents, 'delivery_cents' => $locked->delivery_cents,
                'discount_cents' => $locked->discount_cents, 'total_cents' => $locked->total_cents,
                'tax_cents' => $taxCents, 'vat_rate_bp' => $isTaxInvoice ? $rate : null,
                'seller_vat_number' => $isTaxInvoice ? $sellerVatNumber : null,
                'issued_on' => $issuedOn, 'due_on' => $dueDate, 'issued_by_id' => $by->id,
            ]);
            $locked->update(['tax_cents' => $taxCents]);
            $token = $this->tokens->handle($invoice);
            $this->events->handle($locked, OrderEventType::Note, note: 'Invoice '.$invoice->number.' issued', by: $by);

            return $this->links->handle($invoice, $locked, $token);
        });
    }

    private function validate(Order $order): void
    {
        if (! InvoiceEligibility::acceptsStatus($order->status)) {
            throw ValidationException::withMessages(['status' => 'Only an accepted order can be invoiced.']);
        }
        $active = $order->activeInvoice();
        if ($active !== null) {
            throw ValidationException::withMessages(['invoice' => 'This order already has invoice '.$active->number.'; void it first.']);
        }
        if (! $order->lines()->exists()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line before issuing an invoice.']);
        }
        $this->isTaxInvoice();
    }

    private function isTaxInvoice(): bool
    {
        try {
            return TaxRegistration::isRegistered($this->tax->vat_number, $this->tax->vat_rate_bp);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['tax_settings' => $exception->getMessage()]);
        }
    }

    private function dueDate(?CarbonImmutable $dueOn, CarbonImmutable $issuedOn): CarbonImmutable
    {
        $due = $dueOn === null ? $issuedOn->addDays($this->documents->invoice_due_days) : CarbonImmutable::parse($dueOn->format('Y-m-d'), config('app.timezone'));
        if ($due->lessThan($issuedOn)) {
            throw ValidationException::withMessages(['due_on' => 'The due date cannot be before the issue date.']);
        }

        return $due;
    }

    /** @return array{name: string, company_name: ?string, phone_e164: ?string, email: ?string, address_line1: ?string, address_line2: ?string, city: ?string, vat_number: ?string} */
    private function snapshot(?Customer $customer, Order $order): array
    {
        if ($customer?->anonymised_at !== null) {
            return [
                'name' => 'Removed customer', 'company_name' => null, 'phone_e164' => null, 'email' => null,
                'address_line1' => null, 'address_line2' => null, 'city' => null, 'vat_number' => null,
            ];
        }

        return [
            'name' => $customer === null ? $order->customer_name : ($customer->is_walk_in ? 'Walk-in customer' : $customer->name),
            'company_name' => $customer?->company_name,
            'phone_e164' => $customer === null ? $order->phone_e164 : $customer->phone_e164,
            'email' => $customer?->email, 'address_line1' => $customer?->address_line1,
            'address_line2' => $customer?->address_line2, 'city' => $customer?->city, 'vat_number' => $customer?->vat_number,
        ];
    }
}
