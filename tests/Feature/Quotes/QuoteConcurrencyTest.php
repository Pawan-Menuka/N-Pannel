<?php

use App\Actions\Orders\AcceptQuoteByStaff;
use App\Actions\Orders\CreateCounterSale;
use App\Actions\Orders\CreateQuote;
use App\Actions\Orders\OrderLineInput;
use App\Actions\Orders\SaveQuoteLines;
use App\Actions\Orders\SendQuote;
use App\Actions\Orders\SweepExpired;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Models\Customer;
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
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    $this->primary = DB::getDefaultConnection();
    config([
        'database.connections.quote_owner' => config('database.connections.'.$this->primary),
        'database.connections.quote_peer' => config('database.connections.'.$this->primary),
    ]);
    foreach (['quote_owner', 'quote_peer'] as $name) {
        DB::connection($name)->statement("SET lock_timeout = '5s'");
    }
    DB::setDefaultConnection('quote_owner');
    try {
        [$this->by, $this->customer, $this->variant, $this->location, $this->quote] = DB::transaction(fn (): array => Model::withoutEvents(function (): array {
            $by = User::factory()->sales()->create();
            $customer = Customer::factory()->create();
            $variant = Variant::factory()->create();
            $location = Location::factory()->create(['is_default' => true]);
            StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 1]);
            $quote = app(CreateQuote::class)->handle($customer, $by);
            app(SaveQuoteLines::class)->handle($quote, [new OrderLineInput(OrderLineKind::Product, $variant->id, null, 1, 100)], null, null, CarbonImmutable::parse('2026-11-17', 'Asia/Colombo'), $by);
            app(SendQuote::class)->handle($quote, $by);

            return [$by, $customer, $variant, $location, $quote];
        }));
    } finally {
        DB::setDefaultConnection($this->primary);
    }
    $primary = $this->primary;
    $customerId = $this->customer->id;
    $variantId = $this->variant->id;
    $productId = $this->variant->product_id;
    $categoryId = $this->variant->product->category_id;
    $locationId = $this->location->id;
    $userId = $this->by->id;
    $this->beforeApplicationDestroyed(function () use ($primary, $customerId, $variantId, $productId, $categoryId, $locationId, $userId): void {
        DB::setDefaultConnection($primary);
        foreach (['quote_owner', 'quote_peer'] as $name) {
            $connection = DB::connection($name);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $peer = DB::connection('quote_peer');
        $peer->transaction(function () use ($peer, $customerId, $variantId, $productId, $categoryId, $locationId, $userId): void {
            $peer->table('stock_movements')->where('variant_id', $variantId)->delete();
            $peer->table('orders')->where('customer_id', $customerId)->delete();
            $peer->table('stock_levels')->where('variant_id', $variantId)->delete();
            $peer->table('variants')->where('id', $variantId)->delete();
            $peer->table('products')->where('id', $productId)->delete();
            $peer->table('categories')->where('id', $categoryId)->delete();
            $peer->table('locations')->where('id', $locationId)->delete();
            $peer->table('customers')->where('id', $customerId)->delete();
            $peer->table('users')->where('id', $userId)->delete();
        });
        DB::purge('quote_owner');
        DB::purge('quote_peer');
    });
});

it('serializes quote acceptance and a counter sale competing for the last unit', function (bool $quoteWins) {
    $accept = fn (): Order => Model::withoutEvents(fn (): Order => app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by));
    $sell = fn (): Order => Model::withoutEvents(fn (): Order => app(CreateCounterSale::class)->handle($this->customer, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 1, 100)], $this->by));
    $win = $quoteWins ? $accept : $sell;
    $lose = $quoteWins ? $sell : $accept;
    $owner = DB::connection('quote_owner');
    try {
        DB::setDefaultConnection('quote_owner');
        $owner->beginTransaction();
        $winner = $win();

        DB::setDefaultConnection('quote_peer');
        expect(function () use ($lose): void {
            try {
                DB::transaction(function () use ($lose): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    $lose();
                });
            } catch (QueryException $exception) {
                expect($exception->getSql())->toContain('stock_levels');
                throw $exception;
            }
        })->toThrow(QueryException::class, 'lock timeout');

        $owner->commit();
        expect($lose)->toThrow(ValidationException::class, 'wanted 1, in stock 0');
        expect(StockLevel::query()->where('variant_id', $this->variant->id)->sole()->qty)->toBe(0);
        expect(StockMovement::query()->where('variant_id', $this->variant->id)->sole()->source_id)->toBe($winner->id);
        expect(Order::query()->whereKey($this->quote->id)->sole()->status)->toBe($quoteWins ? OrderStatus::Confirmed : OrderStatus::Quoted);
        expect(Order::query()->where('customer_id', $this->customer->id)->where('status', OrderStatus::Confirmed)->count())->toBe(1);
    } finally {
        if ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        DB::setDefaultConnection($this->primary);
    }
})->with([true, false]);

it('serializes acceptance and expiry on the order lock with exactly one outcome', function (bool $acceptWins) {
    $accept = fn (): Order => Model::withoutEvents(fn (): Order => app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by));
    $deadline = CarbonImmutable::parse('2026-11-18 00:00:00', 'UTC');
    $sweep = fn () => Model::withoutEvents(fn () => app(SweepExpired::class)->handle($deadline));
    $owner = DB::connection('quote_owner');
    try {
        DB::setDefaultConnection('quote_owner');
        $owner->beginTransaction();
        if ($acceptWins) {
            $accept();
        } else {
            expect($sweep()->expiredQuotes)->toBe(1);
        }

        DB::setDefaultConnection('quote_peer');
        $loser = $acceptWins ? $sweep : $accept;
        expect(function () use ($loser): void {
            try {
                DB::transaction(function () use ($loser): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    $loser();
                });
            } catch (QueryException $exception) {
                expect($exception->getSql())->toContain('orders');
                throw $exception;
            }
        })->toThrow(QueryException::class, 'lock timeout');

        $owner->commit();
        if ($acceptWins) {
            expect($sweep()->expiredQuotes)->toBe(0);
        } else {
            expect($accept)->toThrow(ValidationException::class, 'no longer open');
        }
        $quote = Order::query()->findOrFail($this->quote->id);
        expect($quote->status)->toBe($acceptWins ? OrderStatus::Confirmed : OrderStatus::Expired);
        expect($quote->events()->whereIn('to_status', [OrderStatus::Confirmed, OrderStatus::Expired])->count())->toBe(1);
        expect(StockMovement::query()->where('variant_id', $this->variant->id)->count())->toBe($acceptWins ? 1 : 0);
        expect(StockLevel::query()->where('variant_id', $this->variant->id)->sole()->qty)->toBe($acceptWins ? 0 : 1);
    } finally {
        if ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        DB::setDefaultConnection($this->primary);
    }
})->with([true, false]);
