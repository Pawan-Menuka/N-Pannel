<?php

use App\Enums\DocumentStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

it('refuses duplicate stock levels for a variant and location', function () {
    $level = StockLevel::factory()->create();

    expect(fn () => StockLevel::factory()->create([
        'variant_id' => $level->variant_id,
        'location_id' => $level->location_id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses duplicate document sequences for a kind and year', function () {
    $sequence = DocumentSequence::factory()->create();

    expect(fn () => DocumentSequence::factory()->create([
        'kind' => $sequence->kind,
        'year' => $sequence->year,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('preserves variants referenced by the stock ledger', function () {
    $movement = StockMovement::factory()->create();

    expect(fn () => $movement->variant->delete())->toThrow(QueryException::class);
});

it('persists negative stock quantities', function () {
    $level = StockLevel::factory()->create(['qty' => -5]);

    expect($level->fresh()->qty)->toBe(-5);
});

it('preserves categories referenced by products', function () {
    $category = Category::factory()->create();
    Product::factory()->for($category)->create();

    expect(fn () => $category->delete())->toThrow(QueryException::class);
});

it('refuses a second default location', function () {
    Location::factory()->create(['is_default' => true]);

    expect(fn () => Location::factory()->create(['is_default' => true]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a second walk-in customer', function () {
    Customer::factory()->create(['is_walk_in' => true]);

    expect(fn () => Customer::factory()->create(['is_walk_in' => true]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a second issued invoice for an order', function () {
    $order = Order::factory()->create();
    Invoice::factory()->for($order)->create(['status' => DocumentStatus::Issued]);

    expect(fn () => Invoice::factory()->for($order)->create(['status' => DocumentStatus::Issued]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows void and issued invoices for the same order', function () {
    $order = Order::factory()->create();
    $void = Invoice::factory()->for($order)->create(['status' => DocumentStatus::Void]);
    $issued = Invoice::factory()->for($order)->create(['status' => DocumentStatus::Issued]);

    $this->assertModelExists($void);
    $this->assertModelExists($issued);
    expect($order->activeInvoice()?->is($issued))->toBeTrue();
});

it('refuses duplicate non-null document tokens', function (string $modelClass) {
    $tokenHash = str_repeat('a', 64);
    $modelClass::factory()->create(['token_hash' => $tokenHash]);

    expect(fn () => $modelClass::factory()->create(['token_hash' => $tokenHash]))
        ->toThrow(UniqueConstraintViolationException::class);
})->with([
    'orders' => [Order::class],
    'invoices' => [Invoice::class],
]);

it('allows multiple documents without tokens', function (string $modelClass) {
    $modelClass::factory()->count(2)->create(['token_hash' => null]);

    expect($modelClass::whereNull('token_hash')->count())->toBe(2);
})->with([
    'orders' => [Order::class],
    'invoices' => [Invoice::class],
]);
