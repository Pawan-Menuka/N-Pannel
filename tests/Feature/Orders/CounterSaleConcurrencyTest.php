<?php

use App\Actions\Orders\CounterSaleLine;
use App\Actions\Orders\CreateCounterSale;
use App\Enums\OrderLineKind;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('serializes two counter sales of the last unit and refuses the loser without negative stock', function () {
    $primary = DB::getDefaultConnection();
    config([
        'database.connections.sale_owner' => config('database.connections.'.$primary),
        'database.connections.sale_peer' => config('database.connections.'.$primary),
    ]);
    DB::setDefaultConnection('sale_owner');
    try {
        [$by, $customer, $variant, $location, $productId, $categoryId] = DB::transaction(fn (): array => Model::withoutEvents(function (): array {
            $by = User::factory()->sales()->create();
            $customer = Customer::factory()->create();
            $variant = Variant::factory()->create();
            $location = Location::factory()->create(['is_default' => true]);
            StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 1]);

            return [$by, $customer, $variant, $location, $variant->product_id, $variant->product->category_id];
        }));
    } finally {
        DB::setDefaultConnection($primary);
    }
    /** Committed fixtures and two independent transactions exercise the real PostgreSQL stock lock. */
    $this->beforeApplicationDestroyed(function () use ($primary, $by, $customer, $variant, $location, $productId, $categoryId): void {
        DB::setDefaultConnection($primary);
        foreach (['sale_owner', 'sale_peer'] as $name) {
            $connection = DB::connection($name);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $peer = DB::connection('sale_peer');
        $peer->transaction(function () use ($peer, $by, $customer, $variant, $location, $productId, $categoryId): void {
            $peer->table('stock_movements')->where('variant_id', $variant->id)->delete();
            $peer->table('orders')->where('customer_id', $customer->id)->delete();
            $peer->table('stock_levels')->where('variant_id', $variant->id)->delete();
            $peer->table('variants')->where('id', $variant->id)->delete();
            $peer->table('products')->where('id', $productId)->delete();
            $peer->table('categories')->where('id', $categoryId)->delete();
            $peer->table('locations')->where('id', $location->id)->delete();
            $peer->table('customers')->where('id', $customer->id)->delete();
            $peer->table('users')->where('id', $by->id)->delete();
        });
        DB::purge('sale_owner');
        DB::purge('sale_peer');
    });
    $owner = DB::connection('sale_owner');
    $lines = [new CounterSaleLine(OrderLineKind::Product, $variant->id, null, 1, null)];
    $sell = fn (): Order => Model::withoutEvents(fn (): Order => app(CreateCounterSale::class)->handle($customer, $lines, $by));
    try {
        DB::setDefaultConnection('sale_owner');
        $owner->beginTransaction();
        $winner = $sell();

        DB::setDefaultConnection('sale_peer');
        expect(function () use ($sell): void {
            try {
                DB::transaction(function () use ($sell): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    $sell();
                });
            } catch (QueryException $exception) {
                expect($exception->getSql())->toContain('stock_levels');
                throw $exception;
            }
        })->toThrow(QueryException::class, 'lock timeout');

        $owner->commit();
        expect($sell)->toThrow(ValidationException::class, $variant->sku.': wanted 1, in stock 0');
        expect(Order::query()->where('customer_id', $customer->id)->sole()->id)->toBe($winner->id);
        expect(StockLevel::query()->where('variant_id', $variant->id)->where('location_id', $location->id)->sole()->qty)->toBe(0);
        expect(StockMovement::query()->where('variant_id', $variant->id)->sole()->delta)->toBe(-1);
    } finally {
        if ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        DB::setDefaultConnection($primary);
    }
});
