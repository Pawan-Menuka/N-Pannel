<?php

use App\Actions\Invoices\BuildInvoiceDocument;
use App\Actions\Invoices\IssueInvoice;
use App\Actions\Invoices\VoidInvoice;
use App\Actions\Payments\BuildReceiptDocument;
use App\Actions\Payments\RecordPayment;
use App\Actions\Returns\CreateReturn;
use App\Actions\Returns\IssueCreditNote;
use App\Actions\Returns\ReturnLineInput;
use App\Enums\DocumentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Models\CreditNote;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Settings\TaxSettings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Asia/Colombo'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->by = User::factory()->sales()->withAppAuthentication()->create();
    $this->order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'subtotal_cents' => 11800, 'total_cents' => 11800]);
    $this->line = OrderLine::factory()->for($this->order)->create([
        'qty' => 4, 'unit_price_cents' => 2950, 'line_total_cents' => 11800,
        'sku' => 'EARLY-RETURN', 'description' => 'Original returned panel',
    ]);
});

it('issues missing credit notes in return id order when an invoice follows earlier returns', function (bool $taxInvoice) {
    $tax = app(TaxSettings::class);
    $tax->vat_number = $taxInvoice ? 'SELLER-VAT' : null;
    $tax->vat_rate_bp = $taxInvoice ? 1800 : null;
    $tax->save();
    $payment = app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 2950, null, null, $this->by);
    $first = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 2950, false)], 'First early return', null, null, $this->by);
    $second = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 2950, false)], 'Second early return', null, null, $this->by);
    expect($first->creditNote)->toBeNull();
    expect($second->creditNote)->toBeNull();

    $issued = app(IssueInvoice::class)->handle($this->order, null, $this->by);

    $credits = $issued->invoice->creditNotes()->orderBy('id')->get();
    expect($issued->invoice->number)->toBe('INV-2026-00001');
    expect($issued->invoice->total_cents)->toBe(11800);
    expect($issued->invoice->lines[0]['qty'])->toBe(4);
    expect($issued->invoice->tax_cents)->toBe($taxInvoice ? 1800 : 0);
    expect($credits->pluck('number')->all())->toBe(['CRN-2026-00001', 'CRN-2026-00002']);
    expect($credits->pluck('sales_return_id')->all())->toBe([$first->id, $second->id]);
    expect($credits->pluck('tax_cents')->all())->toBe($taxInvoice ? [450, 450] : [0, 0]);
    expect($credits->first()->customer_snapshot)->toEqual($issued->invoice->customer_snapshot);
    expect($credits->first()->lines[0])->toEqual([
        'sku' => 'EARLY-RETURN', 'description' => 'Original returned panel', 'sale_unit' => $this->line->sale_unit?->value,
        'qty' => 1, 'unit_price_cents' => 2950, 'line_total_cents' => 2950,
    ]);
    expect($this->order->refresh()->balanceCents())->toBe(2950);
    expect($issued->invoice->total_cents - $credits->sum('total_cents') - $this->order->paid_cents)->toBe(2950);
    $document = app(BuildInvoiceDocument::class)->handle($issued->invoice);
    expect($document->returned)->toBe('Rs 59.00');
    expect($document->paid)->toBe('Rs 29.50');
    expect($document->balance)->toBe('Rs 29.50');
    $this->get($issued->url)->assertOk()->assertSee('CRN-2026-00001')->assertSee('CRN-2026-00002')->assertSee('Returned');
    $this->actingAs($this->by)->get(route('filament.admin.payments.receipt', ['payment' => $payment]))->assertOk()->assertSee('Returned')->assertSee('Rs 59.00');
})->with([false, true]);

it('links returns after a void to the replacement invoice', function () {
    $old = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    app(VoidInvoice::class)->handle($old, 'Replace incorrect invoice', $this->by);
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 2950, false)], 'Return after void', null, null, $this->by);
    $replacement = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;

    expect($old->fresh()->status)->toBe(DocumentStatus::Void);
    expect($old->creditNotes()->count())->toBe(0);
    expect($replacement->number)->toBe('INV-2026-00002');
    expect($return->fresh()->creditNote->invoice_id)->toBe($replacement->id);
    expect($replacement->creditNotes()->sole()->number)->toBe('CRN-2026-00001');
});

it('rolls back invoice and backfilled credit numbers together', function () {
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 2950, false)], 'Early return', null, null, $this->by);
    expect(fn () => DB::transaction(function (): void {
        app(IssueInvoice::class)->handle($this->order, null, $this->by);
        throw new RuntimeException('Rollback invoice and credits');
    }))->toThrow(RuntimeException::class, 'Rollback invoice and credits');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('credit_notes', 0);
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    expect($invoice->number)->toBe('INV-2026-00001');
    expect($return->fresh()->creditNote->number)->toBe('CRN-2026-00001');
});

it('does not issue a second credit note when the shared issuance step is repeated', function () {
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 2950, false)], 'Early return', null, null, $this->by);
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    $credit = DB::transaction(function () use ($invoice, $return): CreditNote {
        Order::query()->lockForUpdate()->findOrFail($this->order->id);

        return app(IssueCreditNote::class)->handle($invoice, $return, $this->by);
    });
    expect($credit->id)->toBe($return->fresh()->creditNote->id);
    $this->assertDatabaseCount('credit_notes', 1);
    $this->assertDatabaseHas('document_sequences', ['kind' => 'credit_note', 'year' => 2026, 'next_number' => 2]);
});

it('caps discounted return units and accepts all units without consuming delivery value', function () {
    $this->order->update(['subtotal_cents' => 500000, 'discount_cents' => 100000, 'delivery_cents' => 25000, 'total_cents' => 425000]);
    $this->line->update(['qty' => 5, 'unit_price_cents' => 100000, 'line_total_cents' => 500000]);
    expect(app(CreateReturn::class)->defaultUnitValue($this->order, $this->line))->toBe(80000);
    expect(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 80001, false)], 'Too much', null, null, $this->by))
        ->toThrow(ValidationException::class, 'sale price');
    $first = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 80000, false)], 'One panel', null, null, $this->by);
    $last = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 4, 80000, false)], 'Remaining panels', null, null, $this->by);
    expect($first->total_cents)->toBe(80000);
    expect($last->total_cents)->toBe(320000);
    expect($this->order->refresh()->returned_cents)->toBe(400000);
    expect($this->order->balanceCents())->toBe(25000);
});

it('keeps a fully returned unpaid order unpaid with no balance', function () {
    app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 4, 2950, false)], 'All returned unpaid', null, null, $this->by);
    expect($this->order->refresh()->payment_state)->toBe(PaymentState::Unpaid);
    expect($this->order->balanceCents())->toBe(0);
});

it('refuses zero value returns but permits zero value damaged lines alongside valued lines', function () {
    expect(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 0, false)], 'Zero only', null, null, $this->by))
        ->toThrow(ValidationException::class, 'The return total must be more than Rs 0.00.');
    $this->assertDatabaseCount('sales_returns', 0);
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 0, false), new ReturnLineInput($this->line->id, 1, 2950, false)], 'Mixed values', null, null, $this->by);
    expect($return->lines)->toHaveCount(2);
    expect($return->total_cents)->toBe(2950);
});

it('rejects a direct database update crediting more than the order total', function () {
    expect(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $this->order->id)->update(['returned_cents' => 11801])))
        ->toThrow(QueryException::class, 'orders_returned_cents_check');
    expect($this->order->refresh()->returned_cents)->toBe(0);
});

it('shows the returned row on receipts and invoices only when a return exists', function () {
    $payment = app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 2950, null, null, $this->by);
    $issued = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    expect(app(BuildReceiptDocument::class)->handle($payment)->returned)->toBeNull();
    expect(app(BuildInvoiceDocument::class)->handle($issued->invoice)->returned)->toBeNull();
    $url = route('filament.admin.payments.receipt', ['payment' => $payment]);
    $this->actingAs($this->by)->get($url)->assertOk()->assertDontSee('Returned');
    $this->get($issued->url)->assertOk()->assertDontSee('Returned');
    app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 2950, false)], 'Return after receipt', null, null, $this->by);
    $receipt = app(BuildReceiptDocument::class)->handle($payment);
    expect($receipt->total)->toBe('Rs 118.00');
    expect($receipt->returned)->toBe('Rs 29.50');
    expect($receipt->paid)->toBe('Rs 29.50');
    expect($receipt->balance)->toBe('Rs 59.00');
    $this->get($url)->assertOk()->assertSee('Returned')->assertSee('Rs 29.50')->assertSee('Rs 59.00');
    $this->get($issued->url)->assertOk()->assertSee('Returned')->assertSee('CRN-2026-00001')->assertSee('Rs 59.00');
});
