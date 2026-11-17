<?php

use App\Actions\Documents\NextDocumentNumber;
use App\Actions\Orders\RecalculateOrderPayments;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RecordRefund;
use App\Enums\DocumentKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $primary = DB::getDefaultConnection();
    config([
        'database.connections.receipt_owner' => config('database.connections.'.$primary),
        'database.connections.receipt_peer' => config('database.connections.'.$primary),
    ]);
    /** These tests commit isolated sequence years outside RefreshDatabase's main transaction. */
    $this->beforeApplicationDestroyed(function () use ($primary): void {
        DB::setDefaultConnection($primary);
        foreach (['receipt_owner', 'receipt_peer'] as $name) {
            $connection = DB::connection($name);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        DB::connection('receipt_peer')->table('document_sequences')
            ->where('kind', DocumentKind::Receipt->value)->whereIn('year', [2096, 2097])->delete();
        DB::purge('receipt_owner');
        DB::purge('receipt_peer');
    });
});

it('blocks a competing receipt allocator until the sequence owner commits with no number gap', function () {
    $primary = DB::getDefaultConnection();
    $at = CarbonImmutable::parse('2096-06-01', 'Asia/Colombo');
    $numbers = app(NextDocumentNumber::class);
    $owner = DB::connection('receipt_owner');
    $owner->table('document_sequences')->insert([
        'kind' => DocumentKind::Receipt->value, 'year' => 2096, 'next_number' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        DB::setDefaultConnection('receipt_owner');
        $owner->beginTransaction();
        $owner->table('document_sequences')->where('kind', DocumentKind::Receipt->value)->where('year', 2096)->lockForUpdate()->first();
        expect($numbers->handle(DocumentKind::Receipt, $at))->toBe('RCP-2096-00001');

        DB::setDefaultConnection('receipt_peer');
        expect(fn () => DB::transaction(function () use ($numbers, $at): string {
            DB::statement("SET LOCAL lock_timeout = '250ms'");

            return $numbers->handle(DocumentKind::Receipt, $at);
        }))->toThrow(QueryException::class, 'lock timeout');

        $owner->commit();
        expect(DB::transaction(fn (): string => $numbers->handle(DocumentKind::Receipt, $at)))->toBe('RCP-2096-00002');
        expect(DB::connection('receipt_peer')->table('document_sequences')->where('kind', DocumentKind::Receipt->value)->where('year', 2096)->value('next_number'))->toBe(3);
    } finally {
        if ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        DB::setDefaultConnection($primary);
    }
});

it('returns rolled back receipt allocations to both new and existing sequences across connections', function () {
    $primary = DB::getDefaultConnection();
    $at = CarbonImmutable::parse('2097-06-01', 'Asia/Colombo');
    $numbers = app(NextDocumentNumber::class);

    try {
        DB::setDefaultConnection('receipt_owner');
        expect(fn () => DB::transaction(function () use ($numbers, $at): void {
            expect($numbers->handle(DocumentKind::Receipt, $at))->toBe('RCP-2097-00001');
            throw new RuntimeException('Rollback new sequence');
        }))->toThrow(RuntimeException::class, 'Rollback new sequence');

        DB::setDefaultConnection('receipt_peer');
        expect(DB::connection('receipt_peer')->table('document_sequences')->where('kind', DocumentKind::Receipt->value)->where('year', 2097)->exists())->toBeFalse();
        expect(DB::transaction(fn (): string => $numbers->handle(DocumentKind::Receipt, $at)))->toBe('RCP-2097-00001');

        DB::setDefaultConnection('receipt_owner');
        expect(fn () => DB::transaction(function () use ($numbers, $at): void {
            expect($numbers->handle(DocumentKind::Receipt, $at))->toBe('RCP-2097-00002');
            throw new RuntimeException('Rollback existing sequence');
        }))->toThrow(RuntimeException::class, 'Rollback existing sequence');

        DB::setDefaultConnection('receipt_peer');
        expect(DB::transaction(fn (): string => $numbers->handle(DocumentKind::Receipt, $at)))->toBe('RCP-2097-00002');
    } finally {
        DB::setDefaultConnection($primary);
    }
});

it('locks the order before checking the balance and rejects a competing committed ledger entry', function (bool $refund) {
    $primary = DB::getDefaultConnection();
    config(['database.connections.order_peer' => config('database.connections.'.$primary)]);
    DB::setDefaultConnection('order_peer');
    try {
        [$by, $order] = DB::transaction(fn (): array => Model::withoutEvents(function () use ($refund): array {
            $by = User::factory()->sales()->create();
            $order = Order::factory()->create([
                'customer_id' => null, 'created_by_id' => $by->id,
                'status' => $refund ? OrderStatus::Cancelled : OrderStatus::Confirmed,
                'total_cents' => 10000,
            ]);
            if ($refund) {
                $order->payments()->create([
                    'kind' => PaymentKind::Payment, 'method' => PaymentMethod::Cash,
                    'status' => PaymentStatus::Confirmed, 'amount_cents' => 10000,
                    'created_by_id' => $by->id, 'confirmed_by_id' => $by->id, 'confirmed_at' => now(),
                ]);
                app(RecalculateOrderPayments::class)->handle($order);
            }

            return [$by, $order];
        }));
    } finally {
        DB::setDefaultConnection($primary);
    }
    /** Committed fixtures let both connections read and lock the same order. */
    $this->beforeApplicationDestroyed(function () use ($order, $by): void {
        $peer = DB::connection('order_peer');
        while ($peer->transactionLevel() > 0) {
            $peer->rollBack();
        }
        $peer->transaction(function () use ($peer, $order, $by): void {
            $peer->table('payments')->where('order_id', $order->id)->delete();
            $peer->table('order_events')->where('order_id', $order->id)->delete();
            $peer->table('orders')->where('id', $order->id)->delete();
            $peer->table('locations')->where('id', $order->location_id)->delete();
            $peer->table('users')->where('id', $by->id)->delete();
        });
        DB::purge('order_peer');
    });

    $competing = DB::connection('order_peer');
    try {
        DB::setDefaultConnection('order_peer');
        $competing->beginTransaction();
        Model::withoutEvents(function () use ($order, $by, $refund): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $locked->payments()->create([
                'kind' => $refund ? PaymentKind::Refund : PaymentKind::Payment,
                'method' => PaymentMethod::Cash, 'status' => PaymentStatus::Confirmed,
                'amount_cents' => 10000, 'created_by_id' => $by->id,
                'confirmed_by_id' => $by->id, 'confirmed_at' => now(),
            ]);
            app(RecalculateOrderPayments::class)->handle($locked);
        });
        DB::setDefaultConnection($primary);

        $record = function () use ($order, $by, $refund): void {
            if ($refund) {
                app(RecordRefund::class)->handle($order, PaymentMethod::Cash, 10000, 'Concurrent refund', $by);
            } else {
                app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 10000, null, null, $by);
            }
        };
        expect(function () use ($record): void {
            try {
                DB::transaction(function () use ($record): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    $record();
                });
            } catch (QueryException $exception) {
                expect($exception->getSql())->toContain('from "orders"', 'for update');
                throw $exception;
            }
        })->toThrow(QueryException::class, 'lock timeout');

        $competing->commit();
        expect($record)->toThrow(ValidationException::class, $refund ? 'You can refund at most Rs 0.00.' : 'This payment is more than the balance of Rs 0.00.');
        expect($order->payments()->count())->toBe($refund ? 2 : 1);
        expect($order->events()->count())->toBe(0);
        $this->assertDatabaseCount('document_sequences', 0);
    } finally {
        if ($competing->transactionLevel() > 0) {
            $competing->rollBack();
        }
        DB::setDefaultConnection($primary);
    }
})->with(['payment' => false, 'refund' => true]);
