<?php

use App\Actions\Customers\ResolveCustomer;
use App\Actions\Orders\CounterSaleLine;
use App\Actions\Orders\CounterSalePayment;
use App\Actions\Orders\CreateCounterSale;
use App\Actions\Orders\OrderReferenceGenerator;
use App\Enums\OrderEventType;
use App\Enums\OrderLineKind;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Enums\PriceTier;
use App\Enums\StockReason;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 6, 1)->startOfDay());
    $this->by = User::factory()->sales()->create();
    $this->customer = Customer::factory()->create();
    $this->location = Location::factory()->create(['is_default' => true]);
    $this->variant = Variant::factory()->create(['price_cents' => 10000, 'last_cost_cents' => 7000]);
    StockLevel::factory()->create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => 10]);
    $this->line = new CounterSaleLine(OrderLineKind::Product, $this->variant->id, 'Ignored text', 2, null);
});

it('records a walk in cash sale with snapshots a receipt and a stock movement', function () {
    $walkIn = app(ResolveCustomer::class)->walkIn();
    $order = app(CreateCounterSale::class)->handle($walkIn, [$this->line], $this->by, new CounterSalePayment(PaymentMethod::Cash, 20000, null, null));

    expect($order->reference)->toMatch('/^O-[23456789ABCDEFGHJKMNPQRSTVWXYZ]{6}$/D');
    expect($order->source)->toBe(OrderSource::Counter);
    expect($order->status)->toBe(OrderStatus::Confirmed);
    expect($order->confirmed_at->equalTo(now()))->toBeTrue();
    expect($order->payment_state)->toBe(PaymentState::Paid);
    expect($order->customer_name)->toBe('Walk-in customer');
    expect($order->created_by_id)->toBe($this->by->id);
    expect($order->relationLoaded('lines'))->toBeTrue();
    expect($order->relationLoaded('payments'))->toBeTrue();
    $line = $order->lines->sole();
    expect($line->sku)->toBe($this->variant->sku);
    expect($line->description)->toBe($this->variant->displayName());
    expect($line->sale_unit)->toBe($this->variant->sale_unit);
    expect($line->unit_cost_cents)->toBe(7000);
    expect($line->line_total_cents)->toBe(20000);
    expect($order->payments->sole()->receipt_number)->toBe('RCP-2026-00001');
    $movement = StockMovement::sole();
    expect($movement->reason)->toBe(StockReason::CounterSale);
    expect($movement->delta)->toBe(-2);
    expect($movement->qty_after)->toBe(8);
    expect($movement->source->is($order))->toBeTrue();
    expect($movement->user_id)->toBe($this->by->id);
    expect($movement->is_override)->toBeFalse();
    $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'type' => 'status', 'from_status' => null, 'to_status' => 'confirmed', 'note' => 'Counter sale']);
});

it('requires full payment for a walk in sale', function (?int $amount) {
    $payment = $amount === null ? null : new CounterSalePayment(PaymentMethod::Cash, $amount, null, null);

    expect(fn () => app(CreateCounterSale::class)->handle(app(ResolveCustomer::class)->walkIn(), [$this->line], $this->by, $payment))
        ->toThrow(ValidationException::class, 'A walk-in sale must be paid in full.');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('stock_movements', 0);
})->with([null, 1, 19999, 20001]);

it('allows named customers partial or no payment and updates their balance', function (bool $pay) {
    $order = app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by, $pay ? new CounterSalePayment(PaymentMethod::Card, 5000, null, null) : null);

    expect($order->payment_state)->toBe($pay ? PaymentState::PartiallyPaid : PaymentState::Unpaid);
    expect($order->balanceCents())->toBe($pay ? 15000 : 20000);
    expect($this->customer->balanceCents())->toBe($order->balanceCents());
    expect($order->customer_name)->toBe($this->customer->name);
    expect($order->phone_e164)->toBe($this->customer->phone_e164);
    expect($order->email)->toBe($this->customer->email);
})->with([true, false]);

it('uses trade prices with a retail fallback and keeps explicit prices', function (?int $trade, ?int $explicit, int $expected) {
    $this->customer->update(['price_tier' => PriceTier::Trade]);
    $this->variant->update(['trade_price_cents' => $trade]);
    $line = new CounterSaleLine(OrderLineKind::Product, $this->variant->id, null, 1, $explicit);

    $order = app(CreateCounterSale::class)->handle($this->customer, [$line], $this->by);

    expect($order->price_tier)->toBe(PriceTier::Trade);
    expect($order->lines->sole()->unit_price_cents)->toBe($expected);
})->with([[8000, null, 8000], [null, null, 10000], [8000, 1234, 1234], [8000, 0, 0]]);

it('sells untracked variants without a stock movement or level', function () {
    $variant = Variant::factory()->create(['track_stock' => false]);
    $order = app(CreateCounterSale::class)->handle($this->customer, [new CounterSaleLine(OrderLineKind::Product, $variant->id, null, 1, null)], $this->by);

    expect($order->lines)->toHaveCount(1);
    $this->assertDatabaseCount('stock_movements', 0);
    expect($variant->stockLevels()->exists())->toBeFalse();
});

it('aggregates duplicate variants into one movement', function () {
    $order = app(CreateCounterSale::class)->handle($this->customer, [$this->line, $this->line], $this->by);

    expect($order->lines)->toHaveCount(2);
    expect(StockMovement::sole()->delta)->toBe(-4);
    expect(StockLevel::sole()->qty)->toBe(6);
});

it('aggregates valid per line quantities above one million into a single reservation', function () {
    StockLevel::query()->where('variant_id', $this->variant->id)->update(['qty' => 2000000]);
    $line = new CounterSaleLine(OrderLineKind::Product, $this->variant->id, null, 600000, 0);

    app(CreateCounterSale::class)->handle($this->customer, [$line, $line], $this->by);

    expect(StockMovement::sole()->delta)->toBe(-1200000);
    expect(StockLevel::sole()->qty)->toBe(800000);
});

it('locks the order then the receipt sequence then stock in ascending variant order', function () {
    $second = Variant::factory()->create(['price_cents' => 10000]);
    StockLevel::factory()->create(['variant_id' => $second->id, 'location_id' => $this->location->id, 'qty' => 1]);
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (! str_contains($query->sql, 'for update')) {
            return;
        }
        foreach (['orders', 'document_sequences', 'stock_levels'] as $table) {
            if (str_contains($query->sql, 'from "'.$table.'"')) {
                $locks[] = $table;
            }
        }
    });

    app(CreateCounterSale::class)->handle($this->customer, [new CounterSaleLine(OrderLineKind::Product, $second->id, null, 1, null), $this->line], $this->by, new CounterSalePayment(PaymentMethod::Cash, 30000, null, null));

    expect($locks[0])->toBe('orders');
    expect(array_search('document_sequences', $locks, true))->toBeLessThan(array_search('stock_levels', $locks, true));
    expect(StockMovement::query()->orderBy('id')->pluck('variant_id')->all())->toBe([$this->variant->id, $second->id]);
});

it('limits product snapshots to the description column without changing the catalog', function () {
    $this->variant->product->update(['title' => ['en' => str_repeat('P', 255)]]);
    $this->variant->update(['label' => str_repeat('L', 255)]);

    $order = app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by);

    expect(mb_strlen($order->lines->sole()->description))->toBe(255);
    expect($this->variant->fresh()->label)->toBe(str_repeat('L', 255));
});

it('calculates delivery labour other and discount lines with signed discount totals', function () {
    $lines = [$this->line];
    foreach ([OrderLineKind::Delivery, OrderLineKind::Labour, OrderLineKind::Other, OrderLineKind::Discount] as $kind) {
        $lines[] = new CounterSaleLine($kind, null, '  '.$kind->value.'  ', 1, 1000);
    }
    $order = app(CreateCounterSale::class)->handle($this->customer, $lines, $this->by);

    expect($order->subtotal_cents)->toBe(22000);
    expect($order->delivery_cents)->toBe(1000);
    expect($order->discount_cents)->toBe(1000);
    expect($order->total_cents)->toBe(22000);
    $discount = $order->lines->last();
    expect($discount->line_total_cents)->toBe(-1000);
    expect($discount->description)->toBe('discount');
    expect($discount->variant_id)->toBeNull();
    expect($discount->sale_unit)->toBeNull();
});

it('refuses discounts larger than the order', function () {
    expect(fn () => app(CreateCounterSale::class)->handle($this->customer, [$this->line, new CounterSaleLine(OrderLineKind::Discount, null, 'Discount', 1, 20001)], $this->by))
        ->toThrow(ValidationException::class, 'discount is larger');
    $this->assertDatabaseCount('orders', 0);
});

it('marks zero total walk in orders paid without creating a payment', function () {
    $order = app(CreateCounterSale::class)->handle(app(ResolveCustomer::class)->walkIn(), [new CounterSaleLine(OrderLineKind::Product, $this->variant->id, null, 1, 0)], $this->by);

    expect($order->payment_state)->toBe(PaymentState::Paid);
    expect($order->payments)->toHaveCount(0);
    expect(StockMovement::sole()->delta)->toBe(-1);
});

it('reports every shortage and rolls back orders payments events movements activity and receipt allocation', function () {
    $second = Variant::factory()->create(['sku' => 'SHORT-SECOND', 'price_cents' => 10000]);
    StockLevel::factory()->create(['variant_id' => $second->id, 'location_id' => $this->location->id, 'qty' => 1]);
    $available = Variant::factory()->create(['price_cents' => 10000]);
    StockLevel::factory()->create(['variant_id' => $available->id, 'location_id' => $this->location->id, 'qty' => 1]);
    $lines = [new CounterSaleLine(OrderLineKind::Product, $this->variant->id, null, 11, null), new CounterSaleLine(OrderLineKind::Product, $second->id, null, 3, null), new CounterSaleLine(OrderLineKind::Product, $available->id, null, 1, null)];
    $activities = Activity::count();
    try {
        app(CreateCounterSale::class)->handle($this->customer, $lines, $this->by, new CounterSalePayment(PaymentMethod::Cash, 150000, null, null));
        $this->fail('Expected all shortages to be refused.');
    } catch (ValidationException $exception) {
        expect(implode(' ', $exception->errors()['lines']))->toContain($this->variant->sku.': wanted 11, in stock 10', 'SHORT-SECOND: wanted 3, in stock 1');
    }
    foreach (['orders', 'order_lines', 'payments', 'order_events', 'stock_movements', 'document_sequences'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect(Activity::count())->toBe($activities);
    expect(StockLevel::query()->where('variant_id', $this->variant->id)->value('qty'))->toBe(10);
    expect(StockLevel::query()->where('variant_id', $available->id)->value('qty'))->toBe(1);
    $order = app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by, new CounterSalePayment(PaymentMethod::Cash, 20000, null, null));
    expect($order->payments->sole()->receipt_number)->toBe('RCP-2026-00001');
});

it('overrides only short variants and records the reason and affected skus', function () {
    $short = Variant::factory()->create(['price_cents' => 10000]);
    $order = app(CreateCounterSale::class)->handle($this->customer, [$this->line, new CounterSaleLine(OrderLineKind::Product, $short->id, null, 1, null)], $this->by, stockOverrideReason: '  Counted delivery due today  ');

    $movements = StockMovement::query()->orderBy('variant_id')->get();
    expect($movements->first()->is_override)->toBeFalse();
    expect($movements->last()->is_override)->toBeTrue();
    expect($movements->last()->qty_after)->toBe(-1);
    expect($movements->last()->note)->toBe('Counted delivery due today');
    expect($order->events()->where('type', OrderEventType::Note)->sole()->note)->toContain('Stock override: Counted delivery due today', $short->sku);
});

it('maps stock overflow on an override to a line validation error and rolls back', function () {
    StockLevel::query()->where('variant_id', $this->variant->id)->update(['qty' => -2147483648]);

    expect(fn () => app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by, stockOverrideReason: 'Count correction'))
        ->toThrow(ValidationException::class, 'minimum stock quantity');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('stock_movements', 0);
    expect(StockLevel::sole()->qty)->toBe(-2147483648);
});

it('checks override authorization even when stock is sufficient', function () {
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'overrideStock' ? false : null);

    expect(fn () => app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by, stockOverrideReason: 'Reason'))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('orders', 0);
});

it('refuses stock staff', function () {
    expect(fn () => app(CreateCounterSale::class)->handle($this->customer, [$this->line], User::factory()->stock()->create()))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('orders', 0);
});

it('refuses merged and anonymised customers including stale caller models', function (bool $merged) {
    Customer::query()->whereKey($this->customer->id)->update($merged ? ['merged_into_id' => Customer::factory()->create()->id] : ['anonymised_at' => now()]);

    expect(fn () => app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by))->toThrow(ValidationException::class, $merged ? 'surviving customer' : 'another customer');
    $this->assertDatabaseCount('orders', 0);
})->with([true, false]);

it('refuses a deleted customer with a field validation message', function () {
    $this->customer->delete();

    try {
        app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by);
        $this->fail('Expected customer validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['customer_id' => ['This customer no longer exists.']]);
    }
    $this->assertDatabaseCount('orders', 0);
});

it('rechecks customer eligibility under the shared lock before inserting an order', function (string $change) {
    $changed = false;
    $customerId = $this->customer->id;
    $survivor = Customer::factory()->create();
    DB::listen(function (QueryExecuted $query) use (&$changed, $customerId, $survivor, $change): void {
        if ($changed || ! str_contains($query->sql, 'from "customers"') || str_contains($query->sql, 'for share')) {
            return;
        }
        $changed = true;
        $customer = DB::table('customers')->where('id', $customerId);
        if ($change === 'deleted') {
            $customer->delete();
        } else {
            $customer->update($change === 'merged' ? ['merged_into_id' => $survivor->id] : ['anonymised_at' => now()]);
        }
    });

    expect(fn () => app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by))
        ->toThrow(ValidationException::class, match ($change) {
            'deleted' => 'This customer no longer exists.',
            'merged' => 'Pick the surviving customer.',
            'anonymised' => 'Pick another customer.',
        });
    expect($changed)->toBeTrue();
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('stock_movements', 0);
})->with(['deleted', 'merged', 'anonymised']);

it('delegates payment reference validation and leaves nothing written', function (PaymentMethod $method) {
    expect(fn () => app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by, new CounterSalePayment($method, 20000, null, null)))
        ->toThrow(ValidationException::class, 'reference');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('document_sequences', 0);
})->with([PaymentMethod::BankTransfer, PaymentMethod::Cheque]);

it('retries reference collisions using savepoints without aborting the outer transaction', function () {
    Order::factory()->create(['reference' => 'O-222222']);
    $generator = new class extends OrderReferenceGenerator
    {
        public int $calls = 0;

        public function next(string $prefix): string
        {
            return ++$this->calls < 3 ? 'O-222222' : 'O-333333';
        }
    };
    app()->instance(OrderReferenceGenerator::class, $generator);

    $order = app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by, new CounterSalePayment(PaymentMethod::Cash, 20000, null, null));

    expect($generator->calls)->toBe(3);
    expect($order->reference)->toBe('O-333333');
    expect($order->payments->sole()->receipt_number)->toBe('RCP-2026-00001');
    $this->assertDatabaseCount('orders', 2);
    $this->assertDatabaseCount('stock_movements', 1);
});

it('stops after five reference collisions and rolls back', function () {
    Order::factory()->create(['reference' => 'O-222222']);
    $generator = new class extends OrderReferenceGenerator
    {
        public int $calls = 0;

        public function next(string $prefix): string
        {
            $this->calls++;

            return 'O-222222';
        }
    };
    app()->instance(OrderReferenceGenerator::class, $generator);

    expect(fn () => app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by))->toThrow(ValidationException::class, 'reference');
    expect($generator->calls)->toBe(5);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('stock_movements', 0);
});

it('validates product presence line count and each line before writing', function (string $case) {
    $lines = match ($case) {
        'empty' => [],
        'no product' => [new CounterSaleLine(OrderLineKind::Other, null, 'Other', 1, 100)],
        'too many' => array_fill(0, 201, $this->line),
        'missing variant' => [new CounterSaleLine(OrderLineKind::Product, PHP_INT_MAX, null, 1, null)],
        'inactive variant' => [new CounterSaleLine(OrderLineKind::Product, Variant::factory()->create(['is_active' => false])->id, null, 1, null)],
        'nonproduct variant' => [$this->line, new CounterSaleLine(OrderLineKind::Other, $this->variant->id, 'Other', 1, 100)],
        'blank description' => [$this->line, new CounterSaleLine(OrderLineKind::Delivery, null, '  ', 1, 100)],
        'long description' => [$this->line, new CounterSaleLine(OrderLineKind::Other, null, str_repeat('x', 256), 1, 100)],
        'missing price' => [$this->line, new CounterSaleLine(OrderLineKind::Discount, null, 'Discount', 1, null)],
        'zero qty' => [new CounterSaleLine(OrderLineKind::Product, $this->variant->id, null, 0, 100)],
        'large qty' => [new CounterSaleLine(OrderLineKind::Product, $this->variant->id, null, 1000001, 100)],
        'negative price' => [new CounterSaleLine(OrderLineKind::Product, $this->variant->id, null, 1, -1)],
        'large price' => [new CounterSaleLine(OrderLineKind::Product, $this->variant->id, null, 1, 10000000001)],
    };

    expect(fn () => app(CreateCounterSale::class)->handle($this->customer, $lines, $this->by))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('stock_movements', 0);
})->with(['empty', 'no product', 'too many', 'missing variant', 'inactive variant', 'nonproduct variant', 'blank description', 'long description', 'missing price', 'zero qty', 'large qty', 'negative price', 'large price']);

it('rejects invalid named customer payment amounts', function (int $amount) {
    expect(fn () => app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by, new CounterSalePayment(PaymentMethod::Cash, $amount, null, null)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('orders', 0);
})->with([0, -1, 20001]);

it('trims blank overrides and refuses reasons over 255 characters', function () {
    expect(fn () => app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by, stockOverrideReason: str_repeat('x', 256)))->toThrow(ValidationException::class);
    $order = app(CreateCounterSale::class)->handle($this->customer, [$this->line], $this->by, stockOverrideReason: '  ');
    expect($order->events()->where('type', OrderEventType::Note)->exists())->toBeFalse();
    expect(StockMovement::sole()->is_override)->toBeFalse();
});
