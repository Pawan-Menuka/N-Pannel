<?php

use App\Models\Category;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\District;
use App\Models\DocumentSequence;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\QuoteSnapshot;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Variant;

it('persists and refetches each model factory', function (string $modelClass) {
    $model = $modelClass::factory()->create();

    $this->assertModelExists($model);
    expect($modelClass::findOrFail($model->getKey()))->toBeInstanceOf($modelClass);
})->with([
    'User' => [User::class],
    'Location' => [Location::class],
    'District' => [District::class],
    'Category' => [Category::class],
    'Product' => [Product::class],
    'Variant' => [Variant::class],
    'StockLevel' => [StockLevel::class],
    'StockMovement' => [StockMovement::class],
    'Stocktake' => [Stocktake::class],
    'StocktakeLine' => [StocktakeLine::class],
    'Supplier' => [Supplier::class],
    'GoodsReceipt' => [GoodsReceipt::class],
    'GoodsReceiptLine' => [GoodsReceiptLine::class],
    'Customer' => [Customer::class],
    'Order' => [Order::class],
    'OrderLine' => [OrderLine::class],
    'Payment' => [Payment::class],
    'OrderEvent' => [OrderEvent::class],
    'QuoteSnapshot' => [QuoteSnapshot::class],
    'DocumentSequence' => [DocumentSequence::class],
    'Invoice' => [Invoice::class],
    'SalesReturn' => [SalesReturn::class],
    'SalesReturnLine' => [SalesReturnLine::class],
    'CreditNote' => [CreditNote::class],
]);

it('creates an order without a linked customer', function () {
    $order = Order::factory()->create(['customer_id' => null]);

    $this->assertModelExists($order);
    expect($order->customer_id)->toBeNull();
    expect($order->customer_name)->not->toBeEmpty();
    expect($order->phone_e164)->toMatch('/^\\+947[0-9]{8}$/');
    expect(Customer::count())->toBe(0);
});

it('increments quote snapshot revisions per order across batches', function () {
    $order = Order::factory()->create();
    $otherOrder = Order::factory()->create();

    QuoteSnapshot::factory()->count(2)->for($order)->create();
    QuoteSnapshot::factory()->count(2)->for($order)->create();
    QuoteSnapshot::factory()->for($otherOrder)->create();

    expect($order->quoteSnapshots()->orderBy('revision')->pluck('revision')->all())->toBe([1, 2, 3, 4]);
    expect($otherOrder->quoteSnapshots()->sole()->revision)->toBe(1);
});
