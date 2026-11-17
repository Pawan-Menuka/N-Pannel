<?php

use App\Actions\Orders\CreateQuote;
use App\Actions\Orders\OrderLineInput;
use App\Actions\Orders\SaveQuoteLines;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Enums\PriceTier;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\User;
use App\Models\Variant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 18:30:00', 'UTC'));
    $this->by = User::factory()->sales()->create();
    $this->customer = Customer::factory()->create(['price_tier' => PriceTier::Trade]);
    Location::factory()->create(['is_default' => true]);
    $this->variant = Variant::factory()->create(['price_cents' => 10000, 'trade_price_cents' => 8000]);
    $this->quote = app(CreateQuote::class)->handle($this->customer, $this->by);
    $this->date = CarbonImmutable::parse('2026-11-18', 'Asia/Colombo');
});

it('saves tier priced and explicit product prices with signed extras and terms', function () {
    $lines = [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 2, null), new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 1, 1234)];
    foreach ([OrderLineKind::Delivery, OrderLineKind::Labour, OrderLineKind::Other, OrderLineKind::Discount] as $kind) {
        $lines[] = new OrderLineInput($kind, null, '  '.$kind->value.'  ', 1, 1000);
    }

    $quote = app(SaveQuoteLines::class)->handle($this->quote, $lines, 5000, '  Installation excluded  ', $this->date, $this->by);

    expect($quote->lines)->toHaveCount(6);
    expect($quote->lines[0]->unit_price_cents)->toBe(8000);
    expect($quote->lines[1]->unit_price_cents)->toBe(1234);
    expect($quote->subtotal_cents)->toBe(19234);
    expect($quote->delivery_cents)->toBe(1000);
    expect($quote->discount_cents)->toBe(1000);
    expect($quote->total_cents)->toBe(19234);
    expect($quote->required_advance_cents)->toBe(5000);
    expect($quote->exclusions)->toBe('Installation excluded');
    expect($quote->valid_until->format('Y-m-d H:i:s'))->toBe('2026-11-18 23:59:59');
    expect($quote->valid_until->micro)->toBe(0);
    $this->assertDatabaseHas('orders', ['id' => $quote->id, 'valid_until' => '2026-11-18 23:59:59']);
    $this->assertDatabaseCount('stock_movements', 0);
});

it('allows an empty quote and clears its lines and exclusions', function () {
    $action = app(SaveQuoteLines::class);
    $action->handle($this->quote, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 1, null)], null, 'Old', $this->date, $this->by);
    $quote = $action->handle($this->quote, [], 0, '   ', $this->date, $this->by);

    expect($quote->lines)->toHaveCount(0);
    expect($quote->total_cents)->toBe(0);
    expect($quote->exclusions)->toBeNull();
    expect($quote->payment_state)->toBe(PaymentState::Paid);
});

it('validates advance bounds', function (int $advance) {
    expect(fn () => app(SaveQuoteLines::class)->handle($this->quote, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 1, 100)], $advance, null, $this->date, $this->by))
        ->toThrow(ValidationException::class, 'required advance');
    expect($this->quote->fresh()->total_cents)->toBe(0);
})->with([-1, 101]);

it('refuses past local dates exclusions over the limit and inactive variants', function (string $invalid) {
    if ($invalid === 'variant') {
        $this->variant->update(['is_active' => false]);
    }
    expect(fn () => app(SaveQuoteLines::class)->handle($this->quote, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 1, null)], null, $invalid === 'exclusions' ? str_repeat('x', 2001) : null, $invalid === 'date' ? $this->date->subDay() : $this->date, $this->by))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('order_lines', 0);
})->with(['date', 'exclusions', 'variant']);

it('reports a stale closed quote before the gate', function () {
    $this->quote->update(['status' => OrderStatus::Confirmed]);

    expect(fn () => app(SaveQuoteLines::class)->handle($this->quote, [], null, null, $this->date, User::factory()->stock()->create()))
        ->toThrow(ValidationException::class, 'Only an open quote can be changed.');
});

it('recalculates payment state when the total drops below an advance already paid', function () {
    $action = app(SaveQuoteLines::class);
    $action->handle($this->quote, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 1, 10000)], null, null, $this->date, $this->by);
    Payment::factory()->create(['order_id' => $this->quote->id, 'status' => PaymentStatus::Confirmed, 'amount_cents' => 5000]);

    $quote = $action->handle($this->quote, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 1, 4000)], null, null, $this->date, $this->by);

    expect($quote->paid_cents)->toBe(5000);
    expect($quote->payment_state)->toBe(PaymentState::Paid);
    expect($quote->balanceCents())->toBe(0);
});

it('uses retail as the fallback for a trade customer without a trade price', function () {
    $this->variant->update(['trade_price_cents' => null]);
    $quote = app(SaveQuoteLines::class)->handle($this->quote, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 1, null)], 10000, null, $this->date, $this->by);

    expect($quote->lines->sole()->unit_price_cents)->toBe(10000);
});

it('rejects more than 200 lines before authorization', function () {
    $line = new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 1, 0);

    expect(fn () => app(SaveQuoteLines::class)->handle($this->quote, array_fill(0, 201, $line), null, null, $this->date, User::factory()->stock()->create()))->toThrow(ValidationException::class, 'at most 200 lines');
    $this->assertDatabaseCount('order_lines', 0);
});

it('allows exactly 200 lines and 2000 characters of exclusions', function () {
    $line = new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 1, 0);
    $quote = app(SaveQuoteLines::class)->handle($this->quote, array_fill(0, 200, $line), null, str_repeat('x', 2000), $this->date, $this->by);

    expect($quote->lines)->toHaveCount(200);
    expect(mb_strlen($quote->exclusions))->toBe(2000);
});

it('rechecks status under the lock after authorization', function () {
    Gate::before(function (User $user, string $ability): ?bool {
        if ($ability === 'update') {
            $this->quote->update(['status' => OrderStatus::Confirmed]);
        }

        return null;
    });

    expect(fn () => app(SaveQuoteLines::class)->handle($this->quote, [], null, null, $this->date, $this->by))->toThrow(ValidationException::class, 'Only an open quote can be changed.');
    $this->assertDatabaseCount('order_lines', 0);
});

it('authorizes quote changes with the update ability', function () {
    expect(fn () => app(SaveQuoteLines::class)->handle($this->quote, [], null, null, $this->date, User::factory()->stock()->create()))->toThrow(AuthorizationException::class);
});
