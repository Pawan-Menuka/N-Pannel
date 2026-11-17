<?php

use App\Enums\DocumentStatus;
use App\Models\Customer;
use App\Models\District;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('calculates the balance from payments already net of refunds', function () {
    $order = Order::factory()->make(['total_cents' => 10000, 'paid_cents' => 4000, 'refunded_cents' => 1000]);

    expect($order->balanceCents())->toBe(6000);
});

it('floors an overpaid order balance at zero', function () {
    $order = Order::factory()->make(['total_cents' => 10000, 'paid_cents' => 12000, 'refunded_cents' => 0]);

    expect($order->balanceCents())->toBe(0);
});

it('finds the issued invoice and ignores void invoices', function () {
    $order = Order::factory()->create();
    Invoice::factory()->for($order)->create(['status' => DocumentStatus::Void]);
    $invoice = Invoice::factory()->for($order)->create(['status' => DocumentStatus::Issued]);

    expect($order->activeInvoice()?->is($invoice))->toBeTrue();
});

it('has no active invoice when all invoices are void', function () {
    $order = Order::factory()->create();
    Invoice::factory()->for($order)->create(['status' => DocumentStatus::Void]);

    expect($order->activeInvoice())->toBeNull();
});

it('finds the default location', function () {
    Location::factory()->create();
    $location = Location::factory()->create(['is_default' => true]);

    expect(Location::default()->is($location))->toBeTrue();
});

it('requires a default location to exist', function () {
    expect(fn () => Location::default())->toThrow(ModelNotFoundException::class);
});

it('uses the English product title and the variant label or SKU', function (?string $label, string $suffix) {
    $product = Product::factory()->create(['title' => ['en' => 'PVC panel', 'si' => 'Panel SI', 'ta' => 'Panel TA']]);
    $product->setLocale('si');
    $variant = Variant::factory()->for($product)->create(['label' => $label, 'sku' => 'PVC-3000']);
    $variant->setRelation('product', $product);

    expect($variant->displayName())->toBe('PVC panel — '.$suffix);
})->with([
    'label' => ['White 3000 mm', 'White 3000 mm'],
    'empty label' => ['', 'PVC-3000'],
    'null label' => [null, 'PVC-3000'],
]);

it('limits the walk-in scope to walk-in customers', function () {
    Customer::factory()->create(['is_walk_in' => false]);
    $walkIn = Customer::factory()->create(['is_walk_in' => true]);

    expect(Customer::walkIn()->sole()->is($walkIn))->toBeTrue();
});

it('loads the district linked to an order', function () {
    $district = District::factory()->create();
    $order = Order::factory()->for($district)->create();

    expect($order->district->is($district))->toBeTrue();
});
