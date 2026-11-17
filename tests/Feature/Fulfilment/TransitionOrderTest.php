<?php

use App\Actions\Invoices\VoidInvoice;
use App\Actions\Orders\AppendAttentionNote;
use App\Actions\Orders\TransitionOrder;
use App\Actions\Payments\RecordPayment;
use App\Enums\FulfilmentMethod;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

it('requires voiding an active invoice before cancellation even before authorization', function (OrderStatus $status) {
    $order = Order::factory()->create(['status' => $status, 'tax_cents' => 777]);
    $invoice = Invoice::factory()->create(['order_id' => $order->id, 'tax_cents' => 777]);
    $by = User::factory()->sales()->create();
    $reason = in_array($status, [OrderStatus::ReadyForCollection, OrderStatus::Dispatched], true) ? 'not_collected' : 'Changed mind';

    expect(fn () => app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, User::factory()->stock()->create(), cancelReason: $reason))
        ->toThrow(ValidationException::class, 'Void invoice '.$invoice->number.' before cancelling this order.');
    expect($order->fresh()->status)->toBe($status);
    expect($order->fresh()->tax_cents)->toBe(777);
    expect($order->activeInvoice()->id)->toBe($invoice->id);
    expect($order->events()->count())->toBe(0);
    $this->assertDatabaseCount('stock_movements', 0);

    app(VoidInvoice::class)->handle($invoice, 'Cancel order', $by);
    expect(app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, $by, cancelReason: $reason)->status)->toBe(OrderStatus::Cancelled);
    expect($order->fresh()->tax_cents)->toBe(0);
})->with([OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched]);

it('rechecks active invoices under the order lock before cancelling', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    $by = User::factory()->sales()->create();
    Gate::before(function (User $user, string $ability) use ($order): ?bool {
        if ($ability === 'transition') {
            Invoice::factory()->create(['order_id' => $order->id, 'number' => 'INV-2026-99999']);
        }

        return null;
    });

    expect(fn () => app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, $by, cancelReason: 'Changed mind'))
        ->toThrow(ValidationException::class, 'Void invoice INV-2026-99999 before cancelling this order.');
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($order->events()->count())->toBe(0);
    $this->assertDatabaseCount('stock_movements', 0);
});

dataset('staff transitions', [
    ['quoted', 'cancelled'], ['confirmed', 'processing'], ['confirmed', 'cancelled'],
    ['processing', 'ready_for_collection'], ['processing', 'dispatched'], ['processing', 'cancelled'],
    ['ready_for_collection', 'completed'], ['dispatched', 'completed'],
    ['ready_for_collection', 'cancelled'], ['dispatched', 'cancelled'],
]);

it('moves through every supported staff transition and records its effects', function (string $from, string $to) {
    $this->freezeSecond();
    $order = Order::factory()->create(['status' => $from, 'total_cents' => 0]);
    $by = User::factory()->sales()->create();
    $late = in_array($from, ['ready_for_collection', 'dispatched'], true);
    $result = app(TransitionOrder::class)->handle($order, OrderStatus::from($to), $by, ' Staff note ', $late ? 'delivery_refused' : 'Changed mind');

    expect($result->status->value)->toBe($to);
    $event = $result->events()->where('type', OrderEventType::Status)->sole();
    expect($event->from_status->value)->toBe($from);
    expect($event->to_status->value)->toBe($to);
    expect($event->note)->toBe('Staff note');
    expect($event->user_id)->toBe($by->id);
    if ($to === 'cancelled') {
        expect($result->cancelled_at->equalTo(now()))->toBeTrue();
        expect($result->cancel_reason)->toBe($late ? 'delivery_refused' : 'Changed mind');
        expect($result->needs_attention)->toBe($late);
    }
    if ($to === 'completed') {
        expect($result->completed_at->equalTo(now()))->toBeTrue();
    }
    if ($to === 'ready_for_collection' || $to === 'dispatched') {
        expect($result->fulfilment)->toBe($to === 'dispatched' ? FulfilmentMethod::Delivery : FulfilmentMethod::Collection);
    }
})->with('staff transitions');

dataset('unsupported staff transitions', function () {
    $supported = [
        'quoted' => ['cancelled'], 'confirmed' => ['processing', 'cancelled'],
        'processing' => ['ready_for_collection', 'dispatched', 'cancelled'],
        'ready_for_collection' => ['completed', 'cancelled'], 'dispatched' => ['completed', 'cancelled'],
    ];
    foreach (OrderStatus::cases() as $from) {
        foreach (OrderStatus::cases() as $to) {
            if (! in_array($to->value, $supported[$from->value] ?? [], true)) {
                yield $from->value.' to '.$to->value => [$from, $to];
            }
        }
    }
});

it('refuses unsupported transitions with a human message before authorization', function (OrderStatus $from, OrderStatus $to) {
    $order = Order::factory()->create(['status' => $from]);
    try {
        app(TransitionOrder::class)->handle($order, $to, User::factory()->stock()->create());
        $this->fail('Unsupported transition was allowed.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('to');
        expect($exception->errors()['to'][0])->not->toBe('');
    }
    expect($order->fresh()->status)->toBe($from);
    expect($order->events()->count())->toBe(0);
})->with('unsupported staff transitions');

it('refuses stock staff on a valid transition', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    expect(fn () => app(TransitionOrder::class)->handle($order, OrderStatus::Processing, User::factory()->stock()->create()))->toThrow(AuthorizationException::class);
});

it('requires a cancellation reason before authorization', function (string $from, string $reason) {
    $order = Order::factory()->create(['status' => $from]);
    expect(fn () => app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, User::factory()->stock()->create(), cancelReason: $reason))
        ->toThrow(ValidationException::class);
})->with([['confirmed', ' '], ['confirmed', str_repeat('x', 256)], ['dispatched', 'Changed mind'], ['ready_for_collection', '']]);

it('uses the late reason label in the status and attention events', function (string $reason, string $label) {
    $order = Order::factory()->create(['status' => OrderStatus::Dispatched, 'attention_note' => 'Call customer.']);
    $result = app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, User::factory()->sales()->create(), cancelReason: $reason);
    expect($result->attention_note)->toBe('Call customer. Cancelled after Dispatched: '.$label.'. Check what came back and adjust stock.');
    expect($result->events()->where('type', OrderEventType::Status)->sole()->note)->toBe($label);
})->with([['delivery_refused', 'Delivery refused'], ['not_collected', 'Not collected'], ['damaged_in_transit', 'Damaged in transit']]);

it('trims older attention while keeping the newest cancellation note and event', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Dispatched, 'attention_note' => str_repeat('x', 255)]);
    $result = app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, User::factory()->sales()->create(), cancelReason: 'not_collected');
    expect(mb_strlen($result->attention_note))->toBe(255);
    expect($result->attention_note)->toStartWith('… ')->toEndWith('Cancelled after Dispatched: Not collected. Check what came back and adjust stock.');
    expect($result->needs_attention)->toBeTrue();
    expect($result->events()->where('type', OrderEventType::Attention)->sole()->note)->toContain('Not collected');
});

it('adds a refund reminder when cancelling a paid order', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 5000, null, null, $by);
    $result = app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, $by, cancelReason: 'Changed mind');
    expect($result->needs_attention)->toBeTrue();
    expect($result->attention_note)->toBe('Rs 50.00 was paid on this cancelled order — record a refund.');
});

it('refuses completion with money owed before the gate', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Dispatched]);
    expect(fn () => app(TransitionOrder::class)->handle($order, OrderStatus::Completed, User::factory()->stock()->create()))
        ->toThrow(ValidationException::class, 'Rs 1,250.00 is still owed. Record the payment first.');
});

it('allows an admin credit completion with a reason and logs the owed amount', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Dispatched]);
    $result = app(TransitionOrder::class)->handle($order, OrderStatus::Completed, User::factory()->admin()->create(), completeOverrideReason: ' Credit customer ');
    expect($result->status)->toBe(OrderStatus::Completed);
    expect($result->balanceCents())->toBe(125000);
    expect($result->events()->where('type', OrderEventType::Note)->sole()->note)->toBe('Completed with Rs 1,250.00 owed: Credit customer');
});

it('does not let sales override a balance', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Dispatched]);
    expect(fn () => app(TransitionOrder::class)->handle($order, OrderStatus::Completed, User::factory()->sales()->create(), completeOverrideReason: 'Credit'))
        ->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Dispatched);
});

it('completes a paid order after recomputing its payment state', function () {
    $order = Order::factory()->create(['status' => OrderStatus::ReadyForCollection]);
    Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Confirmed, 'amount_cents' => 125000]);
    $result = app(TransitionOrder::class)->handle($order, OrderStatus::Completed, User::factory()->sales()->create());
    expect($result->paid_cents)->toBe(125000);
    expect($result->balanceCents())->toBe(0);
    expect($result->payment_state->value)->toBe('paid');
});

it('rechecks payments under the lock before completing', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Dispatched, 'paid_cents' => 125000]);
    Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Confirmed, 'amount_cents' => 125000]);
    $changed = false;
    Gate::after(function (User $user, string $ability) use ($order, &$changed): void {
        if (! $changed && $ability === 'transition') {
            $changed = true;
            Payment::factory()->create(['order_id' => $order->id, 'kind' => PaymentKind::Refund, 'status' => PaymentStatus::Confirmed, 'amount_cents' => 5000]);
        }
    });
    expect(fn () => app(TransitionOrder::class)->handle($order, OrderStatus::Completed, User::factory()->sales()->create()))
        ->toThrow(ValidationException::class, 'Rs 50.00 is still owed');
    expect($changed)->toBeTrue();
    expect($order->fresh()->status)->toBe(OrderStatus::Dispatched);
});

it('rechecks status under the lock', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    $changed = false;
    Gate::after(function (User $user, string $ability) use ($order, &$changed): void {
        if (! $changed && $ability === 'transition') {
            $changed = true;
            Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Cancelled]);
        }
    });
    expect(fn () => app(TransitionOrder::class)->handle($order, OrderStatus::Processing, User::factory()->sales()->create()))->toThrow(ValidationException::class);
    expect($changed)->toBeTrue();
});

it('caps appended attention at 255 characters inclusively', function (int $length, bool $fits) {
    $order = Order::factory()->create(['attention_note' => str_repeat('x', $length)]);
    DB::transaction(function () use ($order): void {
        app(AppendAttentionNote::class)->handle(Order::query()->lockForUpdate()->findOrFail($order->id), 'New note', null);
    });
    expect($order->fresh()->attention_note)->toBe($fits ? str_repeat('x', $length).' New note' : '… '.str_repeat('x', 244).' New note');
    expect($order->events()->sole()->note)->toBe('New note');
})->with([[246, true], [247, false]]);

it('validates long notes and override reasons before authorization', function (bool $override) {
    $order = Order::factory()->create(['status' => $override ? OrderStatus::Dispatched : OrderStatus::Confirmed]);
    expect(fn () => app(TransitionOrder::class)->handle(
        $order, $override ? OrderStatus::Completed : OrderStatus::Processing, User::factory()->stock()->create(),
        note: $override ? null : str_repeat('x', 256), completeOverrideReason: $override ? str_repeat('x', 256) : null,
    ))->toThrow(ValidationException::class, 'must be at most 255 characters');
})->with([false, true]);

it('trims early cancellation reasons and accepts a 255 character status note', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    $result = app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, User::factory()->sales()->create(), str_repeat('x', 255), ' Changed mind ');
    expect($result->cancel_reason)->toBe('Changed mind');
    expect($result->events()->where('type', OrderEventType::Status)->sole()->note)->toBe(str_repeat('x', 255));
});

it('adds both stock reconciliation and refund attention for a paid late cancellation', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Dispatched]);
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 5000, null, null, $by);
    $result = app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, $by, cancelReason: 'delivery_refused');
    expect($result->attention_note)->toBe('Cancelled after Dispatched: Delivery refused. Check what came back and adjust stock. Rs 50.00 was paid on this cancelled order — record a refund.');
    expect($result->events()->where('type', OrderEventType::Attention)->count())->toBe(2);
});
