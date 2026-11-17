<?php

use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RecordRefund;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SalesReturn;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

it('records a partial refund without a receipt and recalculates the net balance', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['total_cents' => 10000]);
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 10000, null, null, $by);
    $return = SalesReturn::factory()->for($order)->create();

    $refund = DB::transaction(function () use ($order, $by, $return): Payment {
        $order->update(['total_cents' => 7000]);

        return app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 3000, ' Returned items ', $by, $return);
    });

    expect($refund->kind)->toBe(PaymentKind::Refund);
    expect($refund->status)->toBe(PaymentStatus::Confirmed);
    expect($refund->receipt_number)->toBeNull();
    expect($refund->confirmed_at)->not->toBeNull();
    $this->assertDatabaseHas('payments', [
        'id' => $refund->id, 'order_id' => $order->id, 'amount_cents' => 3000,
        'sales_return_id' => $return->id, 'note' => 'Returned items',
        'created_by_id' => $by->id, 'confirmed_by_id' => $by->id,
    ]);
    $order->refresh();
    expect($order->paid_cents)->toBe(7000);
    expect($order->refunded_cents)->toBe(3000);
    expect($order->payment_state)->toBe(PaymentState::Paid);
    expect($order->balanceCents())->toBe(0);
    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id, 'type' => 'payment', 'user_id' => $by->id,
        'note' => 'Refund Rs 30.00 by Cash: Returned items',
    ]);
    expect(Activity::forSubject($refund)->where('event', 'created')->count())->toBe(1);
    $this->assertDatabaseHas('document_sequences', ['kind' => 'receipt', 'year' => now()->year, 'next_number' => 2]);
});

it('fully refunds the remaining net paid after an earlier partial refund', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['total_cents' => 10000]);
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 10000, null, null, $by);
    $order->update(['status' => OrderStatus::Cancelled]);
    app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 3000, 'First return', $by);
    app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 7000, 'Remaining return', $by);

    $order->refresh();
    expect($order->paid_cents)->toBe(0);
    expect($order->refunded_cents)->toBe(10000);
    expect($order->payment_state)->toBe(PaymentState::Refunded);
    expect($order->balanceCents())->toBe(10000);
});

it('refuses refunds above the remaining net paid without writing anything', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['total_cents' => 10000]);
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 10000, null, null, $by);
    $order->update(['status' => OrderStatus::Cancelled]);
    app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 3000, 'First return', $by);
    $activityCount = Activity::count();

    expect(fn () => app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 7001, 'Too much', $by))
        ->toThrow(ValidationException::class, 'You can refund at most Rs 70.00.');

    $this->assertDatabaseCount('payments', 2);
    $this->assertDatabaseCount('order_events', 2);
    expect(Activity::count())->toBe($activityCount);
    expect($order->refresh()->paid_cents)->toBe(7000);
});

it('requires a nonblank note of at most 255 characters', function (string $note) {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create();
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by);
    expect(fn () => app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 1, $note, $by))->toThrow(ValidationException::class, 'note');
    $this->assertDatabaseCount('payments', 1);
})->with(['', '   ', str_repeat('x', 256)]);

it('keeps a full length refund note while bounding its event summary', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create();
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by);
    $order->update(['status' => OrderStatus::Cancelled]);
    $note = str_repeat('x', 255);
    $refund = app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 1, $note, $by);
    expect($refund->note)->toBe($note);
    $prefix = 'Refund Rs 0.01 by Cash: ';
    $summary = $order->events()->orderByDesc('id')->firstOrFail()->note;
    expect($summary)->toBe($prefix.str_repeat('x', 255 - mb_strlen($prefix)));
    expect(mb_strlen($summary))->toBe(255);
});

it('refuses zero and negative refund amounts', function (int $amount) {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create();
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by);
    expect(fn () => app(RecordRefund::class)->handle($order, PaymentMethod::Cash, $amount, 'Return', $by))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('payments', 1);
})->with([0, -1]);

it('refuses refunds when nothing has been paid', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);
    expect(fn () => app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 1, 'Return', $by))->toThrow(ValidationException::class, 'You can refund at most Rs 0.00.');
    $this->assertDatabaseCount('payments', 0);
});

it('checks refund availability against the confirmed ledger instead of stale paid cents', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['total_cents' => 10000, 'paid_cents' => 10000, 'status' => OrderStatus::Cancelled]);
    $order->load('payments');
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'amount_cents' => 6000]);
    expect(fn () => app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 6001, 'Return', $by))
        ->toThrow(ValidationException::class, 'You can refund at most Rs 60.00.');
    $this->assertDatabaseCount('payments', 1);

    app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 6000, 'Return', $by);
    expect($order->refresh()->paid_cents)->toBe(0);
    expect($order->payment_state)->toBe(PaymentState::Refunded);
});

it('refuses staff PayHere refunds', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create();
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by);
    expect(fn () => app(RecordRefund::class)->handle($order, PaymentMethod::Payhere, 1, 'Return', $by))->toThrow(ValidationException::class, 'PayHere');
    $this->assertDatabaseCount('payments', 1);
});

it('allows sales and admins to record refunds using each manual method', function (UserRole $role, PaymentMethod $method) {
    $by = User::factory()->create(['role' => $role]);
    $order = Order::factory()->create();
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by);
    $order->update(['status' => OrderStatus::Cancelled]);
    $refund = app(RecordRefund::class)->handle($order, $method, 1, 'Return', $by);
    $this->assertModelExists($refund);
    expect($refund->method)->toBe($method);
})->with([UserRole::Sales, UserRole::Admin])->with([PaymentMethod::Cash, PaymentMethod::BankTransfer, PaymentMethod::Card, PaymentMethod::Cheque]);

it('refuses stock staff refunds', function () {
    $by = User::factory()->stock()->create();
    $order = Order::factory()->create();
    expect(fn () => app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 1, 'Return', $by))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('order_events', 0);
});

it('refuses refunds that would reopen a balance on any active order without a return', function (OrderStatus $status, int $paid, int $refund) {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['status' => $status, 'total_cents' => 10000]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'amount_cents' => $paid]);

    expect(fn () => app(RecordRefund::class)->handle($order, PaymentMethod::Cash, $refund, 'Return', $by))
        ->toThrow(ValidationException::class, 'Nothing has been overpaid on this order. Cancel the order or record a return first.');

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('order_events', 0);
})->with([OrderStatus::Quoted, OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched, OrderStatus::Completed])->with([6000, 10000])->with([1, 10001]);

it('refunds only the overpayment of an active order without reopening its balance', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'total_cents' => 10000]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'amount_cents' => 13000]);

    expect(fn () => app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 3001, 'Too much', $by))
        ->toThrow(ValidationException::class, 'Only an overpayment of Rs 30.00 can be refunded on an active order. Cancel the order or record a return first.');
    $this->assertDatabaseCount('payments', 1);

    app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 3000, 'Overpayment', $by);
    $order->refresh();
    expect($order->paid_cents)->toBe(10000);
    expect($order->refunded_cents)->toBe(3000);
    expect($order->payment_state)->toBe(PaymentState::Paid);
    expect($order->balanceCents())->toBe(0);
    expect($order->customer->balanceCents())->toBe(0);
    expect(fn () => app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 3000, null, null, $by))
        ->toThrow(ValidationException::class, 'balance of Rs 0.00');
});

it('allows refunds up to net paid on cancelled and expired orders', function (OrderStatus $status) {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['status' => $status, 'total_cents' => 10000]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'amount_cents' => 10000]);
    expect(fn () => app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 10001, 'Too much', $by))
        ->toThrow(ValidationException::class, 'You can refund at most Rs 100.00.');
    app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 10000, 'Order closed', $by);

    expect($order->refresh()->paid_cents)->toBe(0);
    expect($order->payment_state)->toBe(PaymentState::Refunded);
    expect($order->customer->balanceCents())->toBe(0);
})->with([OrderStatus::Cancelled, OrderStatus::Expired]);

it('limits a return refund to net paid even when the credited order has a larger total', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'total_cents' => 10000]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'amount_cents' => 6000]);
    $return = SalesReturn::factory()->for($order)->create();
    expect(fn () => app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 6001, 'Return', $by, $return))
        ->toThrow(ValidationException::class, 'You can refund at most Rs 60.00.');
    $this->assertDatabaseCount('payments', 1);
});

it('refuses a sales return belonging to a different order', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create();
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by);
    $otherReturn = SalesReturn::factory()->create();
    expect(fn () => app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 1, 'Return', $by, $otherReturn))->toThrow(ValidationException::class, 'return');
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('order_events', 1);
    expect($order->refresh()->paid_cents)->toBe(100);
});
