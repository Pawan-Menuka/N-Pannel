<?php

use App\Actions\Orders\SweepExpired;
use App\Actions\Payments\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

it('expires only past due quotes once and records anonymous status events', function (bool $utcClock) {
    $now = CarbonImmutable::parse('2026-11-18 00:00:00', 'Asia/Colombo');
    $this->travelTo($now);
    $expired = Order::factory()->create(['status' => OrderStatus::Quoted, 'valid_until' => $now->subSecond()]);
    $equal = Order::factory()->create(['status' => OrderStatus::Quoted, 'valid_until' => $now]);
    $future = Order::factory()->create(['status' => OrderStatus::Quoted, 'valid_until' => $now->addSecond()]);
    $undated = Order::factory()->create(['status' => OrderStatus::Quoted, 'valid_until' => null]);
    $confirmed = Order::factory()->create(['status' => OrderStatus::Confirmed, 'valid_until' => $now->subDay()]);
    $placed = Order::factory()->create(['status' => OrderStatus::Placed, 'valid_until' => $now->subDay(), 'hold_expires_at' => $now->subHour()]);

    $clock = $utcClock ? $now->utc() : $now;
    expect(app(SweepExpired::class)->handle($clock)->expiredQuotes)->toBe(1);
    expect(app(SweepExpired::class)->handle($clock)->expiredQuotes)->toBe(0);

    expect($expired->fresh()->status)->toBe(OrderStatus::Expired);
    foreach ([$equal, $future, $undated] as $quote) {
        expect($quote->fresh()->status)->toBe(OrderStatus::Quoted);
    }
    expect($confirmed->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($placed->fresh()->status)->toBe(OrderStatus::Placed);
    $this->assertDatabaseHas('order_events', ['order_id' => $expired->id, 'type' => 'status', 'from_status' => 'quoted', 'to_status' => 'expired', 'user_id' => null, 'note' => 'Quote expired (valid until 17 Nov 2026)']);
    $this->assertDatabaseCount('order_events', 1);
    $this->assertDatabaseCount('stock_movements', 0);
})->with([true, false]);

it('runs the sweep command on the hourly schedule', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    $quote = Order::factory()->create(['status' => OrderStatus::Quoted, 'valid_until' => now()->subSecond()]);

    $this->artisan('store:sweep')->expectsOutput('Expired 1 quotes.')->assertSuccessful();
    expect($quote->fresh()->status)->toBe(OrderStatus::Expired);
    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'store:sweep'));
    expect($events)->toHaveCount(1);
    expect($events->sole()->expression)->toBe('0 * * * *');
});

it('processes every chunk even while changing the candidate status', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    Order::factory()->count(101)->create(['status' => OrderStatus::Quoted, 'valid_until' => now()->subSecond()]);

    expect(app(SweepExpired::class)->handle()->expiredQuotes)->toBe(101);
    expect(Order::query()->where('status', OrderStatus::Expired)->count())->toBe(101);
});

it('flags an expired quote with a net advance for refund while leaving unpaid quotes unflagged', function (bool $paid) {
    $this->freezeSecond();
    $quote = Order::factory()->create(['valid_until' => now()->subSecond()]);
    if ($paid) {
        app(RecordPayment::class)->handle($quote, PaymentMethod::Cash, 5000, null, 'Advance', User::factory()->sales()->create());
    }
    /** The sweep must use confirmed payments rather than the cached amount. */
    $quote->update(['paid_cents' => $paid ? 0 : 5000]);
    expect(app(SweepExpired::class)->handle()->expiredQuotes)->toBe(1);
    $quote->refresh();
    expect($quote->status)->toBe(OrderStatus::Expired);
    expect($quote->needs_attention)->toBe($paid);
    expect($quote->paid_cents)->toBe($paid ? 5000 : 0);
    expect($quote->attention_note)->toBe($paid ? 'Rs 50.00 was paid on this expired quote — record a refund.' : null);
    expect($quote->events()->where('type', 'attention')->count())->toBe($paid ? 1 : 0);
    if ($paid) {
        expect($quote->events()->where('type', 'attention')->sole()->note)->toBe('Rs 50.00 was paid on this expired quote — record a refund.');
    }
    expect(app(SweepExpired::class)->handle()->expiredQuotes)->toBe(0);
})->with([false, true]);
