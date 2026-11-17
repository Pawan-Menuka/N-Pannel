<?php

use App\Actions\Orders\AcceptQuoteByStaff;
use App\Actions\Orders\CreateQuote;
use App\Actions\Orders\OrderLineInput;
use App\Actions\Orders\SaveQuoteLines;
use App\Actions\Orders\SendQuote;
use App\Actions\Orders\SweepExpired;
use App\Actions\Orders\TransitionOrder;
use App\Actions\Payments\RecordPayment;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

it('fulfils the blueprint journey including refused delivery and unattended quote expiry', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'Asia/Colombo'));
    $by = User::factory()->sales()->create();
    $customer = Customer::factory()->create();
    $location = Location::factory()->create(['is_default' => true]);
    $variant = Variant::factory()->create();
    StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 10]);
    $quote = app(CreateQuote::class)->handle($customer, $by);
    app(SaveQuoteLines::class)->handle($quote, [new OrderLineInput(OrderLineKind::Product, $variant->id, null, 2, 10000)], 5000, null, CarbonImmutable::parse('2026-11-18', 'Asia/Colombo'), $by);
    app(SendQuote::class)->handle($quote, $by);
    $order = app(AcceptQuoteByStaff::class)->handle($quote, $by);
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 5000, null, 'Advance', $by);
    $order = app(TransitionOrder::class)->handle($order, OrderStatus::Processing, $by);
    $order = app(TransitionOrder::class)->handle($order, OrderStatus::Dispatched, $by);
    expect(fn () => app(TransitionOrder::class)->handle($order, OrderStatus::Completed, $by))->toThrow(ValidationException::class, 'Rs 150.00 is still owed');
    app(RecordPayment::class)->handle($order, PaymentMethod::BankTransfer, 15000, 'BANK-1', null, $by);
    $order = app(TransitionOrder::class)->handle($order, OrderStatus::Completed, $by);
    expect($order->balanceCents())->toBe(0);
    expect($order->completed_at)->not->toBeNull();

    $refused = app(CreateQuote::class)->handle($customer, $by);
    app(SaveQuoteLines::class)->handle($refused, [new OrderLineInput(OrderLineKind::Product, $variant->id, null, 1, 10000)], null, null, CarbonImmutable::parse('2026-11-18', 'Asia/Colombo'), $by);
    app(SendQuote::class)->handle($refused, $by);
    $refused = app(AcceptQuoteByStaff::class)->handle($refused, $by);
    $refused = app(TransitionOrder::class)->handle($refused, OrderStatus::Processing, $by);
    $refused = app(TransitionOrder::class)->handle($refused, OrderStatus::Dispatched, $by);
    $refused = app(TransitionOrder::class)->handle($refused, OrderStatus::Cancelled, $by, cancelReason: 'delivery_refused');
    expect($refused->needs_attention)->toBeTrue();
    expect($refused->attention_note)->toContain('Delivery refused');
    expect(StockLevel::query()->sole()->qty)->toBe(7);
    expect(StockMovement::query()->count())->toBe(2);

    $forgotten = app(CreateQuote::class)->handle($customer, $by);
    $forgotten->update(['valid_until' => now()->addDay()->endOfDay()]);
    $this->travel(3)->days();
    expect(app(SweepExpired::class)->handle()->expiredQuotes)->toBe(1);
    expect($forgotten->fresh()->status)->toBe(OrderStatus::Expired);
    expect(app(SweepExpired::class)->handle()->expiredQuotes)->toBe(0);
});
