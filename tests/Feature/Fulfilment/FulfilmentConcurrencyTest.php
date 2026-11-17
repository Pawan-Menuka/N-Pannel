<?php

use App\Actions\Orders\TransitionOrder;
use App\Actions\Payments\RecordPayment;
use App\Actions\Stock\ReserveStock;
use App\Enums\DocumentKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockReason;
use App\Models\Location;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2098-06-01 12:00:00', 'Asia/Colombo'));
    $this->primary = DB::getDefaultConnection();
    config([
        'database.connections.fulfilment_owner' => config('database.connections.'.$this->primary),
        'database.connections.fulfilment_peer' => config('database.connections.'.$this->primary),
    ]);
    foreach (['fulfilment_owner', 'fulfilment_peer'] as $name) {
        DB::connection($name)->statement("SET lock_timeout = '5s'");
    }
    DB::setDefaultConnection('fulfilment_owner');
    try {
        [$this->by, $this->order, $this->variant, $this->location] = DB::transaction(fn (): array => Model::withoutEvents(function (): array {
            $by = User::factory()->sales()->create();
            $location = Location::factory()->create();
            $variant = Variant::factory()->create();
            $order = Order::factory()->create(['customer_id' => null, 'created_by_id' => $by->id, 'location_id' => $location->id, 'status' => OrderStatus::Confirmed, 'total_cents' => 10000]);
            StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 10]);
            app(ReserveStock::class)->handle($variant, 3, StockReason::CounterSale, $order, $by, location: $location);

            return [$by, $order, $variant, $location];
        }));
    } finally {
        DB::setDefaultConnection($this->primary);
    }
    $primary = $this->primary;
    $orderId = $this->order->id;
    $variantId = $this->variant->id;
    $productId = $this->variant->product_id;
    $categoryId = $this->variant->product->category_id;
    $locationId = $this->location->id;
    $userId = $this->by->id;
    $this->beforeApplicationDestroyed(function () use ($primary, $orderId, $variantId, $productId, $categoryId, $locationId, $userId): void {
        DB::setDefaultConnection($primary);
        foreach (['fulfilment_owner', 'fulfilment_peer'] as $name) {
            $connection = DB::connection($name);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $peer = DB::connection('fulfilment_peer');
        $peer->transaction(function () use ($peer, $orderId, $variantId, $productId, $categoryId, $locationId, $userId): void {
            $peer->table('stock_movements')->where('variant_id', $variantId)->delete();
            $peer->table('payments')->where('order_id', $orderId)->delete();
            $peer->table('orders')->where('id', $orderId)->delete();
            $peer->table('stock_levels')->where('variant_id', $variantId)->delete();
            $peer->table('variants')->where('id', $variantId)->delete();
            $peer->table('products')->where('id', $productId)->delete();
            $peer->table('categories')->where('id', $categoryId)->delete();
            $peer->table('locations')->where('id', $locationId)->delete();
            $peer->table('users')->where('id', $userId)->delete();
            $peer->table('document_sequences')->where('kind', DocumentKind::Receipt->value)->where('year', 2098)->delete();
        });
        DB::purge('fulfilment_owner');
        DB::purge('fulfilment_peer');
    });
});

it('serializes cancellation and payment on the order lock in either arrival order', function (bool $cancelWins) {
    $cancel = fn (): Order => Model::withoutEvents(fn (): Order => app(TransitionOrder::class)->handle($this->order, OrderStatus::Cancelled, $this->by, cancelReason: 'Customer changed mind'));
    $pay = fn () => Model::withoutEvents(fn () => app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 5000, null, null, $this->by));
    $first = $cancelWins ? $cancel : $pay;
    $second = $cancelWins ? $pay : $cancel;
    $owner = DB::connection('fulfilment_owner');
    try {
        DB::setDefaultConnection('fulfilment_owner');
        $owner->beginTransaction();
        $first();
        DB::setDefaultConnection('fulfilment_peer');
        expect(function () use ($second): void {
            try {
                DB::transaction(function () use ($second): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    $second();
                });
            } catch (QueryException $exception) {
                expect($exception->getSql())->toContain('orders');
                throw $exception;
            }
        })->toThrow(QueryException::class, 'lock timeout');
        $owner->commit();
        $second();

        $order = Order::query()->findOrFail($this->order->id);
        expect($order->status)->toBe(OrderStatus::Cancelled);
        expect($order->paid_cents)->toBe(5000);
        expect($order->needs_attention)->toBeTrue();
        expect($order->attention_note)->toContain($cancelWins ? 'received after the order was cancelled' : 'record a refund');
        expect($order->payments()->sole()->receipt_number)->toBe('RCP-2098-00001');
        expect($order->events()->where('type', 'status')->count())->toBe(1);
        expect($order->events()->where('type', 'payment')->count())->toBe(1);
        expect($order->events()->where('type', 'attention')->count())->toBe(1);
        expect(StockLevel::query()->where('variant_id', $this->variant->id)->sole()->qty)->toBe(10);
        expect(StockMovement::query()->where('variant_id', $this->variant->id)->orderBy('id')->pluck('delta')->all())->toBe([-3, 3]);
    } finally {
        if ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        DB::setDefaultConnection($this->primary);
    }
})->with([true, false]);
