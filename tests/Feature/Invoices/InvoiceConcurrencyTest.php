<?php

use App\Actions\Invoices\IssueInvoice;
use App\Enums\DocumentKind;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Settings\BusinessSettings;
use App\Settings\DocumentSettings;
use App\Settings\TaxSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2094-06-01 10:00:00', 'Asia/Colombo'));
    $this->primary = DB::getDefaultConnection();
    config([
        'database.connections.invoice_owner' => config('database.connections.'.$this->primary),
        'database.connections.invoice_peer' => config('database.connections.'.$this->primary),
    ]);
    foreach (['invoice_owner', 'invoice_peer'] as $name) {
        DB::connection($name)->statement("SET lock_timeout = '5s'");
    }
    /** Resolve settings on the test connection before switching to committed fixtures. */
    app(BusinessSettings::class);
    app(DocumentSettings::class);
    $tax = app(TaxSettings::class);
    $tax->vat_number = 'SELLER-VAT';
    $tax->vat_rate_bp = 1800;
    $tax->save();
    $this->issuer = app(IssueInvoice::class);
    $this->orderIds = [];
    $this->locationIds = [];
    $this->userId = null;
    $this->beforeApplicationDestroyed(function (): void {
        DB::setDefaultConnection($this->primary);
        foreach (['invoice_owner', 'invoice_peer'] as $name) {
            $connection = DB::connection($name);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $connection->statement("SET lock_timeout = '5s'");
        }
        $peer = DB::connection('invoice_peer');
        $peer->transaction(function () use ($peer): void {
            $peer->table('invoices')->whereIn('order_id', $this->orderIds)->delete();
            $peer->table('order_events')->whereIn('order_id', $this->orderIds)->delete();
            $peer->table('order_lines')->whereIn('order_id', $this->orderIds)->delete();
            $peer->table('orders')->whereIn('id', $this->orderIds)->delete();
            $peer->table('locations')->whereIn('id', $this->locationIds)->delete();
            if ($this->userId !== null) {
                $peer->table('users')->where('id', $this->userId)->delete();
            }
            $peer->table('document_sequences')->where('kind', DocumentKind::Invoice->value)->where('year', 2094)->delete();
        });
        DB::purge('invoice_owner');
        DB::purge('invoice_peer');
    });
    DB::setDefaultConnection('invoice_owner');
    try {
        [$this->by, $this->firstOrder, $this->secondOrder] = Model::withoutEvents(function (): array {
            return DB::transaction(function (): array {
                $by = User::factory()->sales()->create();
                $this->userId = $by->id;
                $orders = [];
                for ($index = 0; $index < 2; $index++) {
                    $order = Order::factory()->create([
                        'customer_id' => null, 'created_by_id' => $by->id, 'status' => OrderStatus::Confirmed,
                        'subtotal_cents' => 10000, 'total_cents' => 10000,
                    ]);
                    $this->orderIds[] = $order->id;
                    $this->locationIds[] = $order->location_id;
                    OrderLine::factory()->create([
                        'order_id' => $order->id, 'kind' => OrderLineKind::Labour, 'variant_id' => null,
                        'sku' => null, 'sale_unit' => null, 'description' => 'Installation',
                        'unit_price_cents' => 10000, 'line_total_cents' => 10000,
                    ]);
                    $orders[] = $order;
                }

                return [$by, $orders[0], $orders[1]];
            });
        });
    } finally {
        DB::setDefaultConnection($this->primary);
    }
});

it('serializes two issues for one order and refuses the loser after the winner commits', function () {
    $owner = DB::connection('invoice_owner');
    try {
        DB::setDefaultConnection('invoice_owner');
        $owner->beginTransaction();
        $first = Model::withoutEvents(fn () => $this->issuer->handle($this->firstOrder, null, $this->by));
        expect($first->invoice->number)->toBe('INV-2094-00001');

        DB::setDefaultConnection('invoice_peer');
        expect(function (): void {
            try {
                DB::transaction(function (): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    Model::withoutEvents(fn () => $this->issuer->handle($this->firstOrder, null, $this->by));
                });
            } catch (QueryException $exception) {
                expect($exception->getSql())->toContain('from "orders"', 'for update');
                throw $exception;
            }
        })->toThrow(QueryException::class, 'lock timeout');
        $owner->commit();
        expect(fn () => $this->issuer->handle($this->firstOrder, null, $this->by))
            ->toThrow(ValidationException::class, 'already has invoice INV-2094-00001');
        expect(DB::table('invoices')->where('order_id', $this->firstOrder->id)->count())->toBe(1);
        expect(DB::table('document_sequences')->where('kind', 'invoice')->where('year', 2094)->value('next_number'))->toBe(2);
    } finally {
        if ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        DB::setDefaultConnection($this->primary);
    }
});

it('serializes invoice numbers for different orders and allocates consecutive numbers', function () {
    $owner = DB::connection('invoice_owner');
    try {
        DB::setDefaultConnection('invoice_owner');
        $owner->beginTransaction();
        $first = Model::withoutEvents(fn () => $this->issuer->handle($this->firstOrder, null, $this->by));
        DB::setDefaultConnection('invoice_peer');
        expect(fn () => DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '250ms'");
            Model::withoutEvents(fn () => $this->issuer->handle($this->secondOrder, null, $this->by));
        }))->toThrow(QueryException::class, 'lock timeout');
        $owner->commit();
        $second = Model::withoutEvents(fn () => $this->issuer->handle($this->secondOrder, null, $this->by));
        expect($first->invoice->number)->toBe('INV-2094-00001');
        expect($second->invoice->number)->toBe('INV-2094-00002');
        expect(DB::table('invoices')->whereIn('order_id', $this->orderIds)->count())->toBe(2);
    } finally {
        if ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        DB::setDefaultConnection($this->primary);
    }
});

it('reuses a rolled back issue number across connections without gaps', function (bool $existingSequence) {
    $owner = DB::connection('invoice_owner');
    try {
        DB::setDefaultConnection('invoice_owner');
        if ($existingSequence) {
            $owner->table('document_sequences')->insert([
                'kind' => 'invoice', 'year' => 2094, 'next_number' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        expect(fn () => DB::transaction(function (): void {
            $issued = Model::withoutEvents(fn () => $this->issuer->handle($this->firstOrder, null, $this->by));
            expect($issued->invoice->number)->toBe('INV-2094-00001');
            expect($issued->invoice->tax_cents)->toBe(1525);
            expect(DB::table('orders')->where('id', $this->firstOrder->id)->value('tax_cents'))->toBe(1525);
            throw new RuntimeException('Roll back issued invoice');
        }))->toThrow(RuntimeException::class, 'Roll back issued invoice');

        DB::setDefaultConnection('invoice_peer');
        expect(DB::table('invoices')->whereIn('order_id', $this->orderIds)->count())->toBe(0);
        expect(DB::table('orders')->where('id', $this->firstOrder->id)->value('tax_cents'))->toBe(0);
        expect(DB::table('order_events')->where('order_id', $this->firstOrder->id)->count())->toBe(0);
        $first = Model::withoutEvents(fn () => $this->issuer->handle($this->secondOrder, null, $this->by));
        expect($first->invoice->number)->toBe('INV-2094-00001');
        DB::setDefaultConnection('invoice_owner');
        $second = Model::withoutEvents(fn () => $this->issuer->handle($this->firstOrder, null, $this->by));
        expect($second->invoice->number)->toBe('INV-2094-00002');
    } finally {
        DB::setDefaultConnection($this->primary);
    }
})->with([false, true]);
