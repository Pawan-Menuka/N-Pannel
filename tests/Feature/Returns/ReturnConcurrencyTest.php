<?php

use App\Actions\Payments\RecordPayment;
use App\Actions\Returns\CreateReturn;
use App\Actions\Returns\ReturnLineInput;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2093-06-01 10:00:00', 'Asia/Colombo'));
    $this->primary = DB::getDefaultConnection();
    config([
        'database.connections.return_owner' => config('database.connections.'.$this->primary),
        'database.connections.return_peer' => config('database.connections.'.$this->primary),
    ]);
    foreach (['return_owner', 'return_peer'] as $name) {
        DB::connection($name)->statement("SET lock_timeout = '5s'");
    }
    $this->orderId = null;
    $this->locationId = null;
    $this->userId = null;
    $this->beforeApplicationDestroyed(function (): void {
        DB::setDefaultConnection($this->primary);
        foreach (['return_owner', 'return_peer'] as $name) {
            $connection = DB::connection($name);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $peer = DB::connection('return_peer');
        $peer->transaction(function () use ($peer): void {
            $returnIds = $peer->table('sales_returns')->where('order_id', $this->orderId)->pluck('id');
            $peer->table('payments')->where('order_id', $this->orderId)->delete();
            $peer->table('sales_return_lines')->whereIn('sales_return_id', $returnIds)->delete();
            $peer->table('sales_returns')->where('order_id', $this->orderId)->delete();
            $peer->table('order_events')->where('order_id', $this->orderId)->delete();
            $peer->table('order_lines')->where('order_id', $this->orderId)->delete();
            $peer->table('orders')->where('id', $this->orderId)->delete();
            $peer->table('locations')->where('id', $this->locationId)->delete();
            $peer->table('users')->where('id', $this->userId)->delete();
            $peer->table('document_sequences')->where('year', 2093)->where('kind', 'receipt')->delete();
        });
        DB::purge('return_owner');
        DB::purge('return_peer');
    });
    DB::setDefaultConnection('return_owner');
    try {
        [$this->by, $this->order, $this->line] = Model::withoutEvents(function (): array {
            return DB::transaction(function (): array {
                $by = User::factory()->sales()->create();
                $this->userId = $by->id;
                $order = Order::factory()->create([
                    'customer_id' => null, 'created_by_id' => $by->id, 'status' => OrderStatus::Confirmed,
                    'total_cents' => 2000, 'subtotal_cents' => 2000,
                ]);
                $this->orderId = $order->id;
                $this->locationId = $order->location_id;
                $line = OrderLine::factory()->for($order)->create([
                    'variant_id' => null, 'sku' => 'HISTORICAL-PANEL', 'qty' => 1,
                    'unit_price_cents' => 1000, 'line_total_cents' => 1000,
                ]);

                return [$by, $order, $line];
            });
        });
    } finally {
        DB::setDefaultConnection($this->primary);
    }
});

it('serializes two returns of the last unit and refuses the second after commit', function () {
    $owner = DB::connection('return_owner');
    try {
        DB::setDefaultConnection('return_owner');
        $owner->beginTransaction();
        $first = Model::withoutEvents(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'First return', null, null, $this->by));
        DB::setDefaultConnection('return_peer');
        expect(function (): void {
            try {
                DB::transaction(function (): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    Model::withoutEvents(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'Second return', null, null, $this->by));
                });
            } catch (QueryException $exception) {
                expect($exception->getSql())->toContain('from "orders"', 'for update');
                throw $exception;
            }
        })->toThrow(QueryException::class, 'lock timeout');
        $owner->commit();
        expect(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'Second return', null, null, $this->by))
            ->toThrow(ValidationException::class, 'Only 0');
        expect(DB::table('sales_returns')->where('order_id', $this->order->id)->count())->toBe(1);
        expect(DB::table('sales_return_lines')->where('sales_return_id', $first->id)->sum('qty'))->toBe(1);
        expect(Order::query()->findOrFail($this->order->id)->returned_cents)->toBe(1000);
    } finally {
        if ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        DB::setDefaultConnection($this->primary);
    }
});

it('serializes a return racing a payment and recalculates against the committed effective total', function (bool $returnFirst) {
    $owner = DB::connection('return_owner');
    try {
        DB::setDefaultConnection('return_owner');
        $owner->beginTransaction();
        Model::withoutEvents(function () use ($returnFirst): void {
            if ($returnFirst) {
                app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'Return', null, null, $this->by);
            } else {
                app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 2000, null, null, $this->by);
            }
        });
        DB::setDefaultConnection('return_peer');
        expect(fn () => DB::transaction(function () use ($returnFirst): void {
            DB::statement("SET LOCAL lock_timeout = '250ms'");
            Model::withoutEvents(function () use ($returnFirst): void {
                if ($returnFirst) {
                    app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 2000, null, null, $this->by);
                } else {
                    app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'Return', null, null, $this->by);
                }
            });
        }))->toThrow(QueryException::class, 'lock timeout');
        $owner->commit();
        if ($returnFirst) {
            expect(fn () => app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 2000, null, null, $this->by))
                ->toThrow(ValidationException::class, 'balance of Rs 10.00');
            Model::withoutEvents(fn () => app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 1000, null, null, $this->by));
        } else {
            Model::withoutEvents(fn () => app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'Return', null, null, $this->by));
        }
        $order = Order::query()->findOrFail($this->order->id);
        expect($order->returned_cents)->toBe(1000);
        expect($order->paid_cents)->toBe($returnFirst ? 1000 : 2000);
        expect($order->balanceCents())->toBe(0);
        expect($order->payment_state)->toBe(PaymentState::Paid);
    } finally {
        if ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        DB::setDefaultConnection($this->primary);
    }
})->with([false, true]);
