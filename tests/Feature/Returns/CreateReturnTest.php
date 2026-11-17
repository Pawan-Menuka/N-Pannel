<?php

use App\Actions\Invoices\IssueInvoice;
use App\Actions\Invoices\VoidInvoice;
use App\Actions\Orders\TransitionOrder;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RecordRefund;
use App\Actions\Returns\CreateReturn;
use App\Actions\Returns\ReturnLineInput;
use App\Actions\Returns\ReturnRefundInput;
use App\Actions\Stock\StockLedger;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use App\Settings\TaxSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Asia/Colombo'));
    $this->by = User::factory()->sales()->create();
    $this->order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'total_cents' => 4000, 'subtotal_cents' => 4000]);
    $this->variant = Variant::factory()->create(['track_stock' => true]);
    $this->line = OrderLine::factory()->for($this->order)->create([
        'variant_id' => $this->variant->id, 'kind' => OrderLineKind::Product, 'sku' => 'RETURN-PANEL',
        'description' => 'Return panel', 'qty' => 4, 'unit_price_cents' => 1000, 'line_total_cents' => 4000,
    ]);
    StockLevel::factory()->create(['variant_id' => $this->variant->id, 'location_id' => $this->order->location_id, 'qty' => 6]);
});

it('restocks with a return movement and lowers unpaid balances without changing the invoice total', function () {
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 2, 1000, true)], 'Surplus panels', 'Unused', null, $this->by);

    expect($return->total_cents)->toBe(2000);
    expect($return->lines)->toHaveCount(1);
    expect($return->creditNote)->toBeNull();
    expect($return->refund)->toBeNull();
    expect($this->order->refresh()->total_cents)->toBe(4000);
    expect($this->order->returned_cents)->toBe(2000);
    expect($this->order->balanceCents())->toBe(2000);
    expect($this->order->payment_state)->toBe(PaymentState::Unpaid);
    $this->assertDatabaseHas('stock_levels', ['variant_id' => $this->variant->id, 'location_id' => $this->order->location_id, 'qty' => 8]);
    $this->assertDatabaseHas('stock_movements', ['variant_id' => $this->variant->id, 'delta' => 2, 'reason' => 'return_restock', 'source_type' => $return->getMorphClass(), 'source_id' => $return->id]);
    $this->assertDatabaseHas('order_events', ['order_id' => $this->order->id, 'type' => 'note', 'note' => 'Return of 2 items, Rs 20.00: Surplus panels']);
});

it('records damaged goods at a lowered value without restocking', function () {
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 2, 700, false)], 'Damaged', null, null, $this->by);

    expect($return->total_cents)->toBe(1400);
    expect($return->lines->first()->restock)->toBeFalse();
    expect($this->order->refresh()->balanceCents())->toBe(2600);
    expect(StockMovement::query()->count())->toBe(0);
});

it('limits subsequent returns by all earlier returned quantities including damaged goods', function () {
    app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 3, 500, false)], 'First', null, null, $this->by);
    expect(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 2, 500, true)], 'Second', null, null, $this->by))
        ->toThrow(ValidationException::class, 'Only 1');
    app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 500, true)], 'Last', null, null, $this->by);

    expect($this->order->refresh()->returned_cents)->toBe(2000);
    expect(SalesReturnLine::query()->sum('qty'))->toBe(4);
});

it('rejects raised values and cumulative credits above discounted order totals', function (int $value, int $total, string $message) {
    $this->order->update(['total_cents' => $total]);
    expect(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 2, $value, true)], 'Return', null, null, $this->by))
        ->toThrow(ValidationException::class, $message);
    $this->assertDatabaseCount('sales_returns', 0);
    $this->assertDatabaseCount('stock_movements', 0);
    expect($this->order->refresh()->returned_cents)->toBe(0);
})->with([[1001, 4000, 'sale price'], [1000, 1500, 'order total']]);

it('refunds only the overpayment after a return and keeps the order paid', function () {
    app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 4000, null, null, $this->by);
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 2, 1000, true)], 'Surplus', null, new ReturnRefundInput(PaymentMethod::Card, 2000), $this->by);

    expect($return->refund_cents)->toBe(2000);
    expect($return->refund?->sales_return_id)->toBe($return->id);
    expect($return->refund?->method)->toBe(PaymentMethod::Card);
    expect($this->order->refresh()->paid_cents)->toBe(2000);
    expect($this->order->payment_state)->toBe(PaymentState::Paid);
    expect($this->order->balanceCents())->toBe(0);
    expect($this->order->events()->latest('id')->first()->note)->toBe('Return of 2 items, Rs 20.00, refund Rs 20.00: Surplus');
    expect(fn () => app(RecordRefund::class)->handle($this->order, PaymentMethod::Cash, 1, 'Again', $this->by, $return))
        ->toThrow(ValidationException::class, 'Up to Rs 0.00 can be refunded');
});

it('refuses an excessive same-step refund without leaving a return or stock change', function () {
    app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 3000, null, null, $this->by);
    expect(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 2, 1000, true)], 'Surplus', null, new ReturnRefundInput(PaymentMethod::Cash, 1001), $this->by))
        ->toThrow(ValidationException::class, 'Up to Rs 10.00 can be refunded');
    $this->assertDatabaseCount('sales_returns', 0);
    $this->assertDatabaseCount('stock_movements', 0);
    expect($this->order->refresh()->returned_cents)->toBe(0);
});

it('allows the credited overpayment to be refunded later through the ordinary refund action', function () {
    app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 4000, null, null, $this->by);
    app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 4, 1000, false)], 'Full return without immediate refund', null, null, $this->by);
    expect(fn () => app(RecordRefund::class)->handle($this->order, PaymentMethod::Cash, 4001, 'Too much', $this->by))
        ->toThrow(ValidationException::class, 'Only an overpayment of Rs 40.00');
    app(RecordRefund::class)->handle($this->order, PaymentMethod::Cash, 4000, 'Deferred refund', $this->by);
    expect($this->order->refresh()->balanceCents())->toBe(0);
    expect($this->order->paid_cents)->toBe(0);
    expect($this->order->total_cents)->toBe(4000);
});

it('refuses PayHere and nonpositive same-step refunds', function (PaymentMethod $method, int $amount, string $message) {
    app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 4000, null, null, $this->by);
    expect(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, true)], 'Return', null, new ReturnRefundInput($method, $amount), $this->by))
        ->toThrow(ValidationException::class, $message);
    $this->assertDatabaseCount('sales_returns', 0);
})->with([[PaymentMethod::Payhere, 1000, 'PayHere'], [PaymentMethod::Cash, 0, 'Up to Rs 10.00'], [PaymentMethod::Cash, -1, 'Up to Rs 10.00']]);

it('aggregates restock by variant and releases variants in ascending order at the order location', function () {
    $otherVariant = Variant::factory()->create(['track_stock' => true]);
    $second = OrderLine::factory()->for($this->order)->create(['variant_id' => $otherVariant->id, 'qty' => 1, 'unit_price_cents' => 1000, 'line_total_cents' => 1000]);
    $sameVariant = OrderLine::factory()->for($this->order)->create(['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price_cents' => 1000, 'line_total_cents' => 1000]);
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($second->id, 1, 1000, true), new ReturnLineInput($this->line->id, 1, 1000, true), new ReturnLineInput($sameVariant->id, 1, 1000, true)], 'Combined return', null, null, $this->by);
    $movements = StockMovement::query()->where('source_type', $return->getMorphClass())->where('source_id', $return->id)->orderBy('id')->get();
    expect($movements->pluck('variant_id')->all())->toBe([$this->variant->id, $otherVariant->id]);
    expect($movements->pluck('delta')->all())->toBe([2, 1]);
    expect($movements->pluck('location_id')->unique()->all())->toBe([$this->order->location_id]);
});

it('marks a partially paid order paid when the credit covers its balance and updates customer aggregates', function () {
    app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 2000, null, null, $this->by);
    $customer = $this->order->customer;
    expect($customer->balanceCents())->toBe(2000);
    app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 2, 1000, false)], 'Surplus', null, null, $this->by);

    expect($this->order->refresh()->payment_state)->toBe(PaymentState::Paid);
    expect($this->order->balanceCents())->toBe(0);
    expect($customer->balanceCents())->toBe(0);
    expect(Customer::query()->withBalance()->findOrFail($customer->id)->balance_cents)->toBe(0);
    expect(Customer::query()->owingMoney()->whereKey($customer->id)->exists())->toBeFalse();
});

it('issues snapshotted tax and plain credit notes only against the active invoice', function (bool $taxInvoice) {
    $tax = app(TaxSettings::class);
    $tax->vat_number = $taxInvoice ? 'SELLER-VAT' : null;
    $tax->vat_rate_bp = $taxInvoice ? 1800 : null;
    $tax->save();
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    $snapshot = $invoice->customer_snapshot;
    $this->order->customer->update(['name' => 'Changed customer']);
    $tax->vat_rate_bp = 500;
    $tax->save();
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 2, 800, false)], 'Surplus', null, null, $this->by);

    $credit = $return->creditNote;
    expect($credit)->not->toBeNull();
    expect($credit->number)->toBe('CRN-2026-00001');
    expect($credit->invoice_id)->toBe($invoice->id);
    expect($credit->customer_id)->toBe($invoice->customer_id);
    expect($credit->customer_snapshot)->toEqual($snapshot);
    expect($credit->total_cents)->toBe(1600);
    expect($credit->tax_cents)->toBe($taxInvoice ? 244 : 0);
    expect($credit->vat_rate_bp)->toBe($taxInvoice ? 1800 : null);
    expect($credit->seller_vat_number)->toBe($taxInvoice ? 'SELLER-VAT' : null);
    expect($credit->issued_on->toDateString())->toBe('2026-11-18');
    expect($credit->lines)->toEqual([[
        'sku' => 'RETURN-PANEL', 'description' => 'Return panel', 'sale_unit' => $this->line->sale_unit?->value,
        'qty' => 2, 'unit_price_cents' => 800, 'line_total_cents' => 1600,
    ]]);
    expect($invoice->fresh()->total_cents)->toBe(4000);
    expect($this->order->events()->latest('id')->first()->note)->toBe('Return of 2 items, Rs 16.00, credit note CRN-2026-00001: Surplus');
})->with([false, true]);

it('does not issue a credit note against a void invoice', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    app(VoidInvoice::class)->handle($invoice, 'Wrong invoice', $this->by);
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'Surplus', null, null, $this->by);
    expect($return->creditNote)->toBeNull();
    $this->assertDatabaseCount('credit_notes', 0);
});

it('rolls back the credit number stock and balances if a later operation fails', function () {
    app(IssueInvoice::class)->handle($this->order, null, $this->by);
    expect(fn () => DB::transaction(function (): void {
        $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, true)], 'Surplus', null, null, $this->by);
        expect($return->creditNote->number)->toBe('CRN-2026-00001');
        throw new RuntimeException('Rollback');
    }))->toThrow(RuntimeException::class, 'Rollback');
    $this->assertDatabaseCount('sales_returns', 0);
    $this->assertDatabaseCount('credit_notes', 0);
    $this->assertDatabaseCount('stock_movements', 0);
    expect($this->order->refresh()->returned_cents)->toBe(0);
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, true)], 'Surplus', null, null, $this->by);
    expect($return->creditNote->number)->toBe('CRN-2026-00001');
});

it('rolls back an allocated credit number and earlier restock when another variant hits its stock limit', function () {
    $otherVariant = Variant::factory()->create(['track_stock' => true]);
    $other = OrderLine::factory()->for($this->order)->create(['variant_id' => $otherVariant->id, 'qty' => 1, 'unit_price_cents' => 1000, 'line_total_cents' => 1000]);
    StockLevel::factory()->create(['variant_id' => $otherVariant->id, 'location_id' => $this->order->location_id, 'qty' => StockLedger::MaxQty]);
    app(IssueInvoice::class)->handle($this->order, null, $this->by);
    $lines = [new ReturnLineInput($this->line->id, 1, 1000, true), new ReturnLineInput($other->id, 1, 1000, true)];

    expect(fn () => app(CreateReturn::class)->handle($this->order, $lines, 'Surplus', null, null, $this->by))
        ->toThrow(ValidationException::class, 'maximum stock quantity');
    $this->assertDatabaseCount('sales_returns', 0);
    $this->assertDatabaseCount('sales_return_lines', 0);
    $this->assertDatabaseCount('credit_notes', 0);
    $this->assertDatabaseCount('stock_movements', 0);
    $this->assertDatabaseHas('stock_levels', ['variant_id' => $this->variant->id, 'location_id' => $this->order->location_id, 'qty' => 6]);
    expect($this->order->refresh()->returned_cents)->toBe(0);
    StockLevel::query()->where('variant_id', $otherVariant->id)->update(['qty' => 0]);
    $return = app(CreateReturn::class)->handle($this->order, $lines, 'Surplus', null, null, $this->by);
    expect($return->creditNote->number)->toBe('CRN-2026-00001');
});

it('rechecks the refund cap after the gate if another refund reduced the net payment', function () {
    app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 4000, null, null, $this->by);
    $first = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'First credit', null, null, $this->by);
    Gate::after(function (User $user, string $ability, ?bool $result, array $arguments) use ($first): void {
        if ($ability === 'create' && ($arguments[0] ?? null) === SalesReturn::class) {
            app(RecordRefund::class)->handle($this->order, PaymentMethod::Cash, 1000, 'Concurrent refund', $this->by, $first);
        }
    });

    expect(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, true)], 'Second credit', null, new ReturnRefundInput(PaymentMethod::Cash, 2000), $this->by))
        ->toThrow(ValidationException::class, 'Up to Rs 10.00 can be refunded');
    $this->assertDatabaseCount('sales_returns', 1);
    $this->assertDatabaseCount('stock_movements', 0);
    expect($this->order->refresh()->returned_cents)->toBe(1000);
    expect($this->order->paid_cents)->toBe(3000);
});

it('rejects unaccepted statuses before checking the gate', function (OrderStatus $status) {
    $this->order->update(['status' => $status]);
    expect(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, true)], 'Surplus', null, null, User::factory()->stock()->create()))
        ->toThrow(ValidationException::class, 'Only an accepted order');
})->with([OrderStatus::Quoted, OrderStatus::Placed, OrderStatus::Cancelled, OrderStatus::Expired]);

it('allows every returnable status', function (OrderStatus $status) {
    $this->order->update(['status' => $status]);
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'Return', null, null, $this->by);
    $this->assertModelExists($return);
})->with([OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched, OrderStatus::Completed]);

it('refuses stock staff', function () {
    expect(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, true)], 'Surplus', null, null, User::factory()->stock()->create()))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('sales_returns', 0);
});

it('validates reasons notes product membership and nonempty lines before authorizing', function (string $case, string $message) {
    $reason = $case === 'blank' ? ' ' : ($case === 'long reason' ? str_repeat('x', 256) : 'Return');
    $note = $case === 'long note' ? str_repeat('x', 2001) : null;
    if ($case === 'non-product') {
        $this->line->update(['kind' => OrderLineKind::Labour]);
    }
    $lines = $case === 'empty' ? [] : [new ReturnLineInput($case === 'foreign' ? OrderLine::factory()->create()->id : $this->line->id, 1, 1000, false)];
    expect(fn () => app(CreateReturn::class)->handle($this->order, $lines, $reason, $note, null, User::factory()->stock()->create()))
        ->toThrow(ValidationException::class, $message);
    $this->assertDatabaseCount('sales_returns', 0);
})->with([['blank', 'reason'], ['long reason', 'reason'], ['long note', '2,000'], ['non-product', 'product line'], ['foreign', 'product line'], ['empty', 'Choose at least one']]);

it('skips untracked and deleted variants', function (bool $deleted) {
    if ($deleted) {
        $this->line->update(['variant_id' => null]);
    } else {
        $this->variant->update(['track_stock' => false]);
    }
    app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, true)], 'Surplus', null, null, $this->by);
    $this->assertDatabaseCount('stock_movements', 0);
    expect($this->order->refresh()->returned_cents)->toBe(1000);
})->with([false, true]);

it('blocks cancellation and invoice void before the gate after returns', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'Surplus', null, null, $this->by);
    $stock = User::factory()->stock()->create();
    expect(fn () => app(TransitionOrder::class)->handle($this->order, OrderStatus::Cancelled, $stock, cancelReason: 'Cancel'))
        ->toThrow(ValidationException::class, 'This order has returns and cannot be cancelled.');
    expect(fn () => app(VoidInvoice::class)->handle($invoice, 'Void', $stock))
        ->toThrow(ValidationException::class, 'This invoice has credit notes and cannot be voided.');
});

it('rechecks cancellation and void guards after the gate', function (string $operation) {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    if ($operation === 'cancel') {
        app(VoidInvoice::class)->handle($invoice, 'Void before cancellation', $this->by);
    }
    Gate::after(function (User $user, string $ability) use ($operation): void {
        if ($ability === ($operation === 'cancel' ? 'transition' : 'void')) {
            app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'Concurrent return', null, null, $this->by);
        }
    });
    if ($operation === 'cancel') {
        expect(fn () => app(TransitionOrder::class)->handle($this->order, OrderStatus::Cancelled, $this->by, cancelReason: 'Cancel'))
            ->toThrow(ValidationException::class, 'This order has returns');
    } else {
        expect(fn () => app(VoidInvoice::class)->handle($invoice, 'Void', $this->by))
            ->toThrow(ValidationException::class, 'This invoice has credit notes');
    }
})->with(['cancel', 'void']);

it('rechecks return quantities and status under the order lock', function (string $change) {
    Gate::after(function (User $user, string $ability) use ($change): void {
        if ($ability === 'create') {
            if ($change === 'status') {
                $this->order->update(['status' => OrderStatus::Cancelled]);
            } else {
                $this->line->update(['qty' => 0]);
            }
        }
    });
    expect(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, true)], 'Surplus', null, null, $this->by))
        ->toThrow(ValidationException::class, $change === 'status' ? 'Only an accepted' : 'Only 0');
    $this->assertDatabaseCount('sales_returns', 0);
})->with(['status', 'qty']);

it('makes return records lines and credit notes immutable', function (string $model, string $operation) {
    app(IssueInvoice::class)->handle($this->order, null, $this->by);
    $return = app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'Surplus', null, null, $this->by);
    $record = match ($model) {
        'return' => $return, 'line' => $return->lines->first(), 'credit' => $return->creditNote,
    };
    expect(function () use ($record, $operation): void {
        if ($operation === 'delete') {
            $record->delete();
        } elseif ($operation === 'touch') {
            $record->touch();
        } else {
            $record->update(match (true) {
                $record instanceof SalesReturn => ['reason' => 'Changed'],
                $record instanceof SalesReturnLine => ['qty' => 2],
                default => ['total_cents' => 1],
            });
        }
    })->toThrow(LogicException::class);
    $this->assertModelExists($record);
})->with(['return', 'line', 'credit'])->with(['update', 'delete', 'touch']);
