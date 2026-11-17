<?php

use App\Actions\Orders\AcceptQuoteByStaff;
use App\Actions\Orders\CreateCounterSale;
use App\Actions\Orders\CreateQuote;
use App\Actions\Orders\OrderLineInput;
use App\Actions\Orders\SaveQuoteLines;
use App\Actions\Orders\SendQuote;
use App\Actions\Orders\TransitionOrder;
use App\Actions\Stock\ReleaseStock;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Enums\StockReason;
use App\Models\Customer;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('returns exactly the ledger deduction for a sale or accepted quote including repeated lines and overrides', function (bool $quote, bool $override, bool $processing) {
    $by = User::factory()->sales()->create();
    $customer = Customer::factory()->create();
    $location = Location::factory()->create(['is_default' => true]);
    $variant = Variant::factory()->create();
    $untracked = Variant::factory()->create(['track_stock' => false]);
    $opening = $override ? 1 : 20;
    StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => $opening]);
    $lines = [
        new OrderLineInput(OrderLineKind::Product, $variant->id, null, 3, 100),
        new OrderLineInput(OrderLineKind::Product, $variant->id, null, 4, 100),
        new OrderLineInput(OrderLineKind::Product, $untracked->id, null, 2, 100),
    ];
    if ($quote) {
        $order = app(CreateQuote::class)->handle($customer, $by);
        app(SaveQuoteLines::class)->handle($order, $lines, null, null, CarbonImmutable::now('Asia/Colombo')->addDay(), $by);
        app(SendQuote::class)->handle($order, $by);
        $order = app(AcceptQuoteByStaff::class)->handle($order, $by, stockOverrideReason: $override ? 'Customer needs it' : null);
    } else {
        $order = app(CreateCounterSale::class)->handle($customer, $lines, $by, stockOverrideReason: $override ? 'Customer needs it' : null);
    }
    expect(StockLevel::query()->where('variant_id', $variant->id)->sole()->qty)->toBe($opening - 7);
    if ($processing) {
        $order = app(TransitionOrder::class)->handle($order, OrderStatus::Processing, $by);
    }
    /** A prior partial release must not be returned a second time. */
    DB::transaction(fn () => app(ReleaseStock::class)->handle($variant, 2, StockReason::OrderCancelled, $order, $by, location: $location));
    /** Edited line quantities are not the stock ledger. */
    $order->lines()->where('variant_id', $variant->id)->update(['qty' => 99]);
    app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, $by, cancelReason: 'Customer changed mind');

    expect(StockLevel::query()->where('variant_id', $variant->id)->sole()->qty)->toBe($opening);
    $movements = StockMovement::query()->where('source_type', $order->getMorphClass())->where('source_id', $order->id)->orderBy('id')->get();
    expect($movements->pluck('delta')->all())->toBe([-7, 2, 5]);
    expect($movements->sum('delta'))->toBe(0);
    expect($movements->last()->reason)->toBe(StockReason::OrderCancelled);
    expect(StockMovement::query()->where('variant_id', $untracked->id)->count())->toBe(0);
})->with([false, true])->with([false, true])->with([false, true]);

it('withdraws a quote without releasing any stock', function () {
    $by = User::factory()->sales()->create();
    $location = Location::factory()->create(['is_default' => true]);
    $variant = Variant::factory()->create();
    StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 10]);
    $order = app(CreateQuote::class)->handle(Customer::factory()->create(), $by);
    app(SaveQuoteLines::class)->handle($order, [new OrderLineInput(OrderLineKind::Product, $variant->id, null, 3, 100)], null, null, CarbonImmutable::now('Asia/Colombo')->addDay(), $by);
    app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, $by, cancelReason: 'Withdrawn');
    expect(StockLevel::query()->sole()->qty)->toBe(10);
    expect(StockMovement::query()->count())->toBe(0);
});

it('does not release stock on late cancellation', function (OrderStatus $from) {
    $by = User::factory()->sales()->create();
    $location = Location::factory()->create(['is_default' => true]);
    $variant = Variant::factory()->create();
    StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 10]);
    $order = app(CreateCounterSale::class)->handle(Customer::factory()->create(), [new OrderLineInput(OrderLineKind::Product, $variant->id, null, 3, 100)], $by);
    $order = app(TransitionOrder::class)->handle($order, OrderStatus::Processing, $by);
    $order = app(TransitionOrder::class)->handle($order, $from, $by);
    $result = app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, $by, cancelReason: 'delivery_refused');
    expect($result->needs_attention)->toBeTrue();
    expect(StockLevel::query()->sole()->qty)->toBe(7);
    expect(StockMovement::query()->count())->toBe(1);
})->with([OrderStatus::ReadyForCollection, OrderStatus::Dispatched]);

it('returns quantities exceeding the per-call limit and restores historically tracked stock', function () {
    $by = User::factory()->sales()->create();
    $location = Location::factory()->create(['is_default' => true]);
    $variant = Variant::factory()->create();
    StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 1500000]);
    $order = app(CreateCounterSale::class)->handle(Customer::factory()->create(), [
        new OrderLineInput(OrderLineKind::Product, $variant->id, null, 1000000, 0),
        new OrderLineInput(OrderLineKind::Product, $variant->id, null, 500000, 0),
    ], $by);
    $variant->update(['track_stock' => false]);
    app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, $by, cancelReason: 'Duplicate order');
    expect(StockLevel::query()->sole()->qty)->toBe(1500000);
    expect($variant->fresh()->track_stock)->toBeFalse();
    expect(StockMovement::query()->orderBy('id')->pluck('delta')->all())->toBe([-1500000, 1000000, 500000]);
});

it('releases variants in ascending order and ignores other sources and nonnegative nets', function () {
    $by = User::factory()->sales()->create();
    $customer = Customer::factory()->create();
    $location = Location::factory()->create(['is_default' => true]);
    $first = Variant::factory()->create();
    $second = Variant::factory()->create();
    $third = Variant::factory()->create();
    foreach ([$first, $second, $third] as $variant) {
        StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 10]);
    }
    $order = app(CreateCounterSale::class)->handle($customer, [
        new OrderLineInput(OrderLineKind::Product, $second->id, null, 3, 100),
        new OrderLineInput(OrderLineKind::Product, $third->id, null, 2, 100),
        new OrderLineInput(OrderLineKind::Product, $first->id, null, 1, 100),
    ], $by);
    DB::transaction(function () use ($third, $second, $order, $customer, $by, $location): void {
        app(ReleaseStock::class)->handle($third, 2, StockReason::OrderCancelled, $order, $by, location: $location);
        app(ReleaseStock::class)->handle($second, 1, StockReason::ManualAdjust, $customer, $by, location: $location);
    });
    $before = StockMovement::query()->max('id');
    app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, $by, cancelReason: 'Duplicate');
    $releases = StockMovement::query()->where('id', '>', $before)->orderBy('id')->get();
    expect($releases->pluck('variant_id')->all())->toBe([$first->id, $second->id]);
    expect($releases->pluck('delta')->all())->toBe([1, 3]);
    expect(StockLevel::query()->where('variant_id', $second->id)->sole()->qty)->toBe(11);
});
