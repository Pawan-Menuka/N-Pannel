<?php

use App\Actions\Payments\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

it('records a confirmed part payment with its receipt event and activity log', function () {
    $this->travelTo(now()->setDate(2026, 6, 1)->startOfDay());
    $by = User::factory()->sales()->create();
    $this->actingAs($by);
    $order = Order::factory()->create(['total_cents' => 10000, 'status' => OrderStatus::Confirmed]);

    $payment = app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 4000, null, ' Deposit ', $by);

    expect($payment->kind)->toBe(PaymentKind::Payment);
    expect($payment->status)->toBe(PaymentStatus::Confirmed);
    expect($payment->confirmed_at->equalTo(now()))->toBeTrue();
    $this->assertDatabaseHas('payments', [
        'id' => $payment->id, 'order_id' => $order->id, 'amount_cents' => 4000,
        'created_by_id' => $by->id, 'confirmed_by_id' => $by->id,
        'receipt_number' => 'RCP-2026-00001', 'note' => 'Deposit',
    ]);
    $order->refresh();
    expect($order->paid_cents)->toBe(4000);
    expect($order->refunded_cents)->toBe(0);
    expect($order->payment_state)->toBe(PaymentState::PartiallyPaid);
    expect($order->balanceCents())->toBe(6000);
    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id, 'type' => 'payment', 'user_id' => $by->id,
        'from_status' => null, 'to_status' => null,
        'note' => 'Payment Rs 40.00 by Cash, receipt RCP-2026-00001',
    ]);
    expect(Activity::forSubject($payment)->where('event', 'created')->count())->toBe(1);
});

it('settles the order and allocates consecutive receipt numbers', function () {
    $this->travelTo(now()->setDate(2026, 6, 1)->startOfDay());
    $by = User::factory()->admin()->create();
    $order = Order::factory()->create(['total_cents' => 10000]);
    $action = app(RecordPayment::class);
    $first = $action->handle($order, PaymentMethod::Cash, 4000, null, null, $by);
    $second = $action->handle($order, PaymentMethod::Card, 6000, null, null, $by);

    expect($first->receipt_number)->toBe('RCP-2026-00001');
    expect($second->receipt_number)->toBe('RCP-2026-00002');
    expect($order->refresh()->payment_state)->toBe(PaymentState::Paid);
    expect($order->paid_cents)->toBe(10000);
    expect($order->balanceCents())->toBe(0);
});

it('checks confirmed ledger payments rather than stale order totals or loaded relations', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['total_cents' => 10000, 'paid_cents' => 0]);
    $order->load('payments');
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'amount_cents' => 6000]);

    expect(fn () => app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 4001, null, null, $by))
        ->toThrow(ValidationException::class, 'balance of Rs 40.00');

    $payment = app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 4000, null, null, $by);
    $this->assertModelExists($payment);
    expect($order->refresh()->paid_cents)->toBe(10000);
    expect($order->payment_state)->toBe(PaymentState::Paid);
});

it('accepts the reference and note length limit and normalizes blank optional text', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create();
    $text = str_repeat('x', 255);
    $payment = app(RecordPayment::class)->handle($order, PaymentMethod::Cheque, 1, $text, $text, $by);
    expect($payment->reference)->toBe($text);
    expect($payment->note)->toBe($text);

    $payment = app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 1, '  ', '  ', $by);
    expect($payment->reference)->toBeNull();
    expect($payment->note)->toBeNull();
});

it('rejects an overpayment without any writes or consuming a receipt number', function () {
    $this->travelTo(now()->setDate(2026, 6, 1)->startOfDay());
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['total_cents' => 10000]);
    $activityCount = Activity::count();

    expect(fn () => app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 10001, null, null, $by))
        ->toThrow(ValidationException::class, 'This payment is more than the balance of Rs 100.00.');

    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('order_events', 0);
    $this->assertDatabaseCount('document_sequences', 0);
    expect(Activity::count())->toBe($activityCount);
    expect($order->refresh()->paid_cents)->toBe(0);
    expect(app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 10000, null, null, $by)->receipt_number)->toBe('RCP-2026-00001');
});

it('checks the balance in every active order status', function (OrderStatus $status) {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['total_cents' => 100, 'status' => $status]);
    expect(fn () => app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 101, null, null, $by))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('payments', 0);
})->with([OrderStatus::Quoted, OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched, OrderStatus::Completed]);

it('refuses a payment on a zero balance order', function (bool $alreadyPaid) {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['total_cents' => $alreadyPaid ? 100 : 0]);
    if ($alreadyPaid) {
        app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by);
    }
    expect(fn () => app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 1, null, null, $by))->toThrow(ValidationException::class, 'balance of Rs 0.00');
    expect($order->payments()->count())->toBe($alreadyPaid ? 1 : 0);
})->with([false, true]);

it('requires a trimmed bank or cheque reference', function (PaymentMethod $method, ?string $reference) {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create();
    expect(fn () => app(RecordPayment::class)->handle($order, $method, 100, $reference, null, $by))->toThrow(ValidationException::class, 'reference');
    $this->assertDatabaseCount('payments', 0);
})->with([PaymentMethod::BankTransfer, PaymentMethod::Cheque])->with([null, '', '   ']);

it('accepts each staff method and trims optional text', function (PaymentMethod $method) {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create();
    $payment = app(RecordPayment::class)->handle($order, $method, 100, ' REF-1 ', ' Note ', $by);
    expect($payment->method)->toBe($method);
    expect($payment->reference)->toBe('REF-1');
    expect($payment->note)->toBe('Note');
})->with([PaymentMethod::Cash, PaymentMethod::BankTransfer, PaymentMethod::Card, PaymentMethod::Cheque]);

it('bounds payment amounts', function (int $amount) {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);
    expect(fn () => app(RecordPayment::class)->handle($order, PaymentMethod::Cash, $amount, null, null, $by))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('payments', 0);
})->with([0, -1, 10000000001, PHP_INT_MAX]);

it('bounds reference and note lengths', function (string $field) {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create();
    expect(fn () => app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, $field === 'reference' ? str_repeat('x', 256) : null, $field === 'note' ? str_repeat('x', 256) : null, $by))->toThrow(ValidationException::class, $field);
    $this->assertDatabaseCount('payments', 0);
})->with(['reference', 'note']);

it('refuses staff PayHere payments', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create();
    expect(fn () => app(RecordPayment::class)->handle($order, PaymentMethod::Payhere, 100, null, null, $by))->toThrow(ValidationException::class, 'PayHere payments are recorded by the gateway.');
    $this->assertDatabaseCount('payments', 0);
});

it('authorizes both sales and admins to record payments', function (UserRole $role) {
    $by = User::factory()->create(['role' => $role]);
    $order = Order::factory()->create();
    $payment = app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by);
    $this->assertModelExists($payment);
})->with([UserRole::Sales, UserRole::Admin]);

it('refuses stock staff before writing a payment', function () {
    $by = User::factory()->stock()->create();
    $order = Order::factory()->create();
    expect(fn () => app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('order_events', 0);
    $this->assertDatabaseCount('document_sequences', 0);
});

it('records late payments beyond the balance and flags attention without changing order status', function (OrderStatus $status) {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['total_cents' => 10000, 'status' => $status]);
    $payment = app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 10000000000, null, null, $by);
    $this->assertModelExists($payment);
    $order->refresh();
    expect($order->status)->toBe($status);
    expect($order->needs_attention)->toBeTrue();
    expect($order->attention_note)->toBe('Payment of Rs 100,000,000.00 received after the order was '.$status->value.'.');
    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id, 'type' => 'attention', 'user_id' => $by->id,
        'note' => 'Payment of Rs 100,000,000.00 received after the order was '.$status->value.'.',
    ]);
    expect($order->paid_cents)->toBe(10000000000);
    expect($order->payment_state)->toBe(PaymentState::Paid);
    expect($order->balanceCents())->toBe(0);
})->with([OrderStatus::Cancelled, OrderStatus::Expired]);

it('appends late payment attention while keeping an event for every late payment', function () {
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled, 'needs_attention' => true, 'attention_note' => 'Stock still needs checking.']);
    $first = 'Payment of Rs 1.00 received after the order was cancelled.';
    $second = 'Payment of Rs 2.00 received after the order was cancelled.';

    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by);
    expect($order->refresh()->attention_note)->toBe('Stock still needs checking. '.$first);
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 200, null, null, $by);
    expect($order->refresh()->attention_note)->toBe('Stock still needs checking. '.$first.' '.$second);
    expect($order->events()->where('type', 'attention')->orderBy('id')->pluck('note')->all())->toBe([$first, $second]);
    expect($order->needs_attention)->toBeTrue();
});

it('appends attention at the length limit and preserves the original beyond it', function (int $overflow) {
    $by = User::factory()->sales()->create();
    $line = 'Payment of Rs 1.00 received after the order was expired.';
    $existing = str_repeat('x', 255 - mb_strlen($line) - 1 + $overflow);
    $order = Order::factory()->create(['status' => OrderStatus::Expired, 'attention_note' => $existing]);

    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by);

    expect($order->refresh()->attention_note)->toBe($overflow === 0 ? $existing.' '.$line : $existing);
    expect($order->needs_attention)->toBeTrue();
    $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'type' => 'attention', 'note' => $line, 'user_id' => $by->id]);
})->with([0, 1, 57]);

it('rolls back payments receipts and events when the outer transaction fails', function () {
    $this->travelTo(now()->setDate(2026, 6, 1)->startOfDay());
    $by = User::factory()->sales()->create();
    $order = Order::factory()->create();
    expect(fn () => DB::transaction(function () use ($order, $by): void {
        app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by);
        throw new RuntimeException('Rollback');
    }))->toThrow(RuntimeException::class, 'Rollback');
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('order_events', 0);
    expect($order->refresh()->paid_cents)->toBe(0);
    expect(app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 100, null, null, $by)->receipt_number)->toBe('RCP-2026-00001');
});
