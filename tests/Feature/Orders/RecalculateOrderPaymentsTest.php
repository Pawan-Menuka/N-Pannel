<?php

use App\Actions\Orders\RecalculateOrderPayments;
use App\Domain\Order\AmountTooLarge;
use App\Enums\PaymentKind;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

it('rebuilds payment totals only from confirmed ledger entries', function () {
    $order = Order::factory()->create(['total_cents' => 10000, 'paid_cents' => 999, 'refunded_cents' => 999]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'amount_cents' => 10000]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'kind' => PaymentKind::Refund, 'amount_cents' => 3000]);
    foreach ([PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::Cancelled] as $status) {
        foreach (PaymentKind::cases() as $kind) {
            Payment::factory()->for($order)->create(['status' => $status, 'kind' => $kind, 'amount_cents' => 100000]);
        }
    }
    $order->load('payments');

    DB::transaction(function () use ($order): void {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
        app(RecalculateOrderPayments::class)->handle($locked);
    });

    $order->refresh();
    expect($order->paid_cents)->toBe(7000);
    expect($order->refunded_cents)->toBe(3000);
    expect($order->payment_state)->toBe(PaymentState::PartiallyPaid);
});

it('guards payment and refund aggregate overflow', function (PaymentKind $kind) {
    $order = Order::factory()->create();
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'kind' => $kind, 'amount_cents' => PHP_INT_MAX]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'kind' => $kind, 'amount_cents' => 1]);
    expect(fn () => DB::transaction(function () use ($order): void {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
        app(RecalculateOrderPayments::class)->handle($locked);
    }))->toThrow(AmountTooLarge::class);
    expect($order->refresh()->paid_cents)->toBe(0);
})->with(PaymentKind::cases());

it('requires a transaction before recalculating payment state', function () {
    $order = Order::factory()->make();
    $manager = DB::getFacadeRoot();
    DB::shouldReceive('transactionLevel')->once()->andReturn(0);
    try {
        expect(fn () => app(RecalculateOrderPayments::class)->handle($order))->toThrow(LogicException::class);
    } finally {
        DB::swap($manager);
    }
});
