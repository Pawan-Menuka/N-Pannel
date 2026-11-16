<?php

use App\Actions\Stock\AdjustStock;
use App\Actions\Stock\ReleaseStock;
use App\Actions\Stock\ReserveStock;
use App\Actions\Stock\StockLedger;
use App\Domain\Stock\InsufficientStock;
use App\Domain\Stock\StockLimitExceeded;
use App\Enums\StockReason;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\Stocktake;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->location = Location::factory()->create(['is_default' => true]);
    $this->actor = User::factory()->stock()->create();
    $this->variant = Variant::factory()->create();
    $this->source = Stocktake::factory()->create(['location_id' => $this->location->id]);
});

it('lazily creates a stock level and records the complete movement', function () {
    $movement = DB::transaction(fn () => app(StockLedger::class)->apply($this->variant, $this->location, 8, StockReason::Stocktake, $this->source, $this->actor, '  Opening stock  '));

    $this->assertDatabaseHas('stock_levels', ['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => 8]);
    expect($movement->qty_after)->toBe(8);
    expect($movement->delta)->toBe(8);
    expect($movement->reason)->toBe(StockReason::Stocktake);
    expect($movement->user_id)->toBe($this->actor->id);
    expect($movement->source->is($this->source))->toBeTrue();
    expect($movement->note)->toBe('Opening stock');
    $this->assertDatabaseCount('stock_movements', 1);
});

it('refuses shortages atomically', function () {
    StockLevel::factory()->create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => 2]);

    expect(fn () => DB::transaction(fn () => app(StockLedger::class)->apply($this->variant, $this->location, -3, StockReason::CounterSale, $this->source, null)))->toThrow(InsufficientStock::class);

    $this->assertDatabaseHas('stock_levels', ['variant_id' => $this->variant->id, 'qty' => 2]);
    $this->assertDatabaseCount('stock_movements', 0);
});

it('records an explicit override below zero including a numeric reason', function () {
    $movement = DB::transaction(fn () => app(StockLedger::class)->apply($this->variant, $this->location, -3, StockReason::CounterSale, $this->source, null, ' 0 ', true));

    expect($movement->qty_after)->toBe(-3);
    expect($movement->is_override)->toBeTrue();
    expect($movement->note)->toBe('0');
});

it('refuses zero changes and blank override reasons without writing', function (int $delta, ?string $note, bool $override) {
    expect(fn () => DB::transaction(fn () => app(StockLedger::class)->apply($this->variant, $this->location, $delta, StockReason::ManualAdjust, null, null, $note, $override)))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('stock_levels', 0);
    $this->assertDatabaseCount('stock_movements', 0);
})->with([[0, null, false], [-1, null, true], [-1, '   ', true]]);

it('requires a transaction even outside the test transaction', function () {
    $manager = DB::getFacadeRoot();
    DB::shouldReceive('transactionLevel')->once()->andReturn(0);
    try {
        expect(fn () => app(StockLedger::class)->apply($this->variant, $this->location, 1, StockReason::Import, null, null))->toThrow(LogicException::class);
    } finally {
        DB::swap($manager);
    }
    $this->assertDatabaseCount('stock_movements', 0);
});

it('allows stock and admin manual adjustments in both directions', function (string $role) {
    $actor = User::factory()->{$role}()->create();
    app(AdjustStock::class)->handle($this->variant, 8, '  Received  ', $actor);
    $movement = app(AdjustStock::class)->handle($this->variant, -3, ' Damaged ', $actor);

    expect($movement->qty_after)->toBe(5);
    expect($movement->note)->toBe('Damaged');
    expect($movement->reason)->toBe(StockReason::ManualAdjust);
    expect($movement->source_id)->toBeNull();
    $this->assertDatabaseCount('stock_movements', 2);
})->with(['stock', 'admin']);

it('returns adjustment validation on the matching field', function (int $delta, string $note, bool $tracked, string $field) {
    $this->variant->update(['track_stock' => $tracked]);

    try {
        app(AdjustStock::class)->handle($this->variant, $delta, $note, $this->actor);
        $this->fail('Expected validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('stock_movements', 0);
    $this->assertDatabaseCount('stock_levels', 0);
})->with([[0, 'reason', true, 'delta'], [1000001, 'reason', true, 'delta'], [-1000001, 'reason', true, 'delta'], [1, ' ', true, 'note'], [1, str_repeat('a', 256), true, 'note'], [1, 'reason', false, 'delta'], [-1, 'reason', true, 'delta']]);

it('forbids manual adjustment by sales', function () {
    expect(fn () => app(AdjustStock::class)->handle($this->variant, 1, 'reason', User::factory()->sales()->create()))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('stock_movements', 0);
});

it('reserves exact stock and rejects a second reserve that exceeds the remaining stock', function () {
    app(ReleaseStock::class)->handle($this->variant, 5, StockReason::GoodsReceived, $this->source, $this->actor);
    $reserved = app(ReserveStock::class)->handle($this->variant, 3, StockReason::CounterSale, $this->source, $this->actor);
    expect($reserved->qty_after)->toBe(2);
    expect(fn () => app(ReserveStock::class)->handle($this->variant, 3, StockReason::CounterSale, $this->source))->toThrow(InsufficientStock::class);
    $exact = app(ReserveStock::class)->handle($this->variant, 2, StockReason::CounterSale, $this->source);

    expect($exact->qty_after)->toBe(0);
    expect($exact->source->is($this->source))->toBeTrue();
    $this->assertDatabaseCount('stock_movements', 3);
});

it('can reserve below zero with an explicit override', function () {
    $movement = app(ReserveStock::class)->handle($this->variant, 4, StockReason::CounterSale, $this->source, overrideReason: ' Approved shortage ');

    expect($movement->qty_after)->toBe(-4);
    expect($movement->is_override)->toBeTrue();
    expect($movement->note)->toBe('Approved shortage');
});

it('ignores untracked variants for reserve and release', function () {
    $this->variant->update(['track_stock' => false]);

    expect(app(ReserveStock::class)->handle($this->variant, 1, StockReason::CounterSale, $this->source))->toBeNull();
    expect(app(ReleaseStock::class)->handle($this->variant, 1, StockReason::OrderCancelled, $this->source))->toBeNull();
    $this->assertDatabaseCount('stock_movements', 0);
    $this->assertDatabaseCount('stock_levels', 0);
});

it('rejects invalid reservation and release quantities', function (string $action, int $qty) {
    expect(fn () => app($action)->handle($this->variant, $qty, StockReason::CounterSale, $this->source))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('stock_movements', 0);
})->with([
    [ReserveStock::class, 0], [ReserveStock::class, -1], [ReserveStock::class, 1000001],
    [ReleaseStock::class, 0], [ReleaseStock::class, -1], [ReleaseStock::class, 1000001],
]);

it('reserves and releases at the supplied location only', function () {
    $other = Location::factory()->create();
    app(ReleaseStock::class)->handle($this->variant, 6, StockReason::GoodsReceived, $this->source, location: $other);
    app(ReserveStock::class)->handle($this->variant, 2, StockReason::CounterSale, $this->source, location: $other);

    $this->assertDatabaseHas('stock_levels', ['variant_id' => $this->variant->id, 'location_id' => $other->id, 'qty' => 4]);
    $this->assertDatabaseMissing('stock_levels', ['variant_id' => $this->variant->id, 'location_id' => $this->location->id]);
});

it('increments safely to the maximum stock quantity and refuses an overflow without another movement', function () {
    StockLevel::factory()->create([
        'variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => 2147483646,
    ]);

    $movement = DB::transaction(fn () => app(StockLedger::class)->apply(
        $this->variant, $this->location, 1, StockReason::GoodsReceived, $this->source, $this->actor,
    ));
    expect($movement->qty_after)->toBe(2147483647);
    expect(fn () => DB::transaction(fn () => app(StockLedger::class)->apply(
        $this->variant, $this->location, 1, StockReason::GoodsReceived, $this->source, $this->actor,
    )))->toThrow(StockLimitExceeded::class);

    $this->assertDatabaseHas('stock_levels', ['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => 2147483647]);
    $this->assertDatabaseCount('stock_movements', 1);
});

it('decrements safely to the minimum stock quantity and refuses an underflow', function (bool $override, bool $guardNegative) {
    StockLevel::factory()->create([
        'variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => -2147483647,
    ]);

    $movement = DB::transaction(fn () => app(StockLedger::class)->apply(
        $this->variant, $this->location, -1, StockReason::CounterSale, $this->source, $this->actor,
        note: 'Approved shortage', override: $override, guardNegative: $guardNegative,
    ));
    expect($movement->qty_after)->toBe(-2147483648);
    expect(fn () => DB::transaction(fn () => app(StockLedger::class)->apply(
        $this->variant, $this->location, -1, StockReason::CounterSale, $this->source, $this->actor,
        note: 'Approved shortage', override: $override, guardNegative: $guardNegative,
    )))->toThrow(StockLimitExceeded::class);

    $this->assertDatabaseHas('stock_levels', ['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => -2147483648]);
    $this->assertDatabaseCount('stock_movements', 1);
})->with([
    'explicit override' => [true, true],
    'unguarded ledger change' => [false, false],
]);

it('refuses movement deltas outside the database integer range before writing', function (int $delta) {
    expect(fn () => DB::transaction(fn () => app(StockLedger::class)->apply(
        $this->variant, $this->location, $delta, StockReason::Stocktake, $this->source, $this->actor,
        guardNegative: false,
    )))->toThrow(StockLimitExceeded::class);

    $this->assertDatabaseCount('stock_levels', 0);
    $this->assertDatabaseCount('stock_movements', 0);
})->with([2147483648, -2147483649]);

it('maps adjustment overflow to the delta validation field', function () {
    StockLevel::factory()->create([
        'variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => 2147483647,
    ]);

    try {
        app(AdjustStock::class)->handle($this->variant, 1, 'More stock', $this->actor);
        $this->fail('Expected stock limit validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['delta' => ['That would exceed the maximum stock quantity.']]);
    }

    $this->assertDatabaseHas('stock_levels', ['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => 2147483647]);
    $this->assertDatabaseCount('stock_movements', 0);
});

it('enforces stock quantity limits through reserve and release', function () {
    $level = StockLevel::factory()->create([
        'variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => 2147483647,
    ]);

    expect(fn () => app(ReleaseStock::class)->handle(
        $this->variant, 1, StockReason::OrderCancelled, $this->source,
    ))->toThrow(StockLimitExceeded::class);
    expect($level->fresh()->qty)->toBe(2147483647);

    $level->update(['qty' => -2147483648]);
    expect(fn () => app(ReserveStock::class)->handle(
        $this->variant, 1, StockReason::CounterSale, $this->source, overrideReason: 'Approved shortage',
    ))->toThrow(StockLimitExceeded::class);

    expect($level->fresh()->qty)->toBe(-2147483648);
    $this->assertDatabaseCount('stock_movements', 0);
});

it('accepts the maximum reservation and release input quantity', function () {
    $released = app(ReleaseStock::class)->handle($this->variant, 1000000, StockReason::GoodsReceived, $this->source);
    $reserved = app(ReserveStock::class)->handle($this->variant, 1000000, StockReason::CounterSale, $this->source);

    expect($released->qty_after)->toBe(1000000);
    expect($reserved->qty_after)->toBe(0);
    $this->assertDatabaseCount('stock_movements', 2);
});
