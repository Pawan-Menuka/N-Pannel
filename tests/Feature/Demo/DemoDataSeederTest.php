<?php

use App\Actions\Reports\DashboardData;
use App\Enums\LateCancelReason;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\StockReason;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Variant;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('seeds a balanced month of purchases and sales across a year boundary', function () {
    config(['seed.sample_catalog' => true]);
    $this->seed(DatabaseSeeder::class);
    $startedAt = CarbonImmutable::parse('2027-01-10 13:30:00', 'Asia/Colombo');
    $this->travelTo($startedAt);

    $this->seed(DemoDataSeeder::class);

    expect(Carbon::getTestNow())->toBeNull()
        ->and(CarbonImmutable::getTestNow())->toBeNull()
        ->and(Product::where('slug', 'like', 'demo-%')->count())->toBe(6)
        ->and(Variant::where('sku', 'like', 'DEMO-%')->count())->toBe(15)
        ->and(Variant::where('sku', 'PVC-WHT-3000')->sole()->price_cents)->toBe(125000)
        ->and(Supplier::count())->toBe(3)
        ->and(GoodsReceipt::whereNotNull('posted_at')->count())->toBe(4)
        ->and(Customer::where('is_walk_in', false)->count())->toBe(11)
        ->and(Order::where('source', OrderSource::Counter)->count())->toBeGreaterThanOrEqual(18)->toBeLessThanOrEqual(24)
        ->and(Order::where('source', OrderSource::Quote)->count())->toBe(6)
        ->and(Invoice::count())->toBe(5);

    $levels = StockLevel::all()->keyBy(fn (StockLevel $level): string => $level->variant_id.':'.$level->location_id);
    $movements = StockMovement::query()->select('variant_id', 'location_id')->selectRaw('SUM(delta) AS quantity')
        ->groupBy('variant_id', 'location_id')->get()
        ->keyBy(fn (StockMovement $movement): string => $movement->variant_id.':'.$movement->location_id);

    foreach ($levels as $key => $level) {
        expect($level->qty)->toBeGreaterThanOrEqual(0)->toBe((int) ($movements->get($key)?->getAttribute('quantity') ?? 0));
    }
    expect($levels->keys()->all())->toContain(...$movements->keys()->all())
        ->and(StockMovement::where('reason', StockReason::ManualAdjust)->sole()->delta)->toBe(-2);

    foreach ([
        ['goods_receipts', 'number', 'posted_at', 'GRN'],
        ['payments', 'receipt_number', 'confirmed_at', 'RCP'],
        ['invoices', 'number', 'issued_on', 'INV'],
        ['credit_notes', 'number', 'issued_on', 'CRN'],
    ] as [$table, $numberColumn, $dateColumn, $prefix]) {
        $documents = DB::table($table)->whereNotNull($numberColumn)->orderBy($dateColumn)->orderBy('id')->get();
        expect($documents)->not->toBeEmpty();
        $numbersByYear = [];
        foreach ($documents as $document) {
            $year = substr($document->{$dateColumn}, 0, 4);
            $numbersByYear[$year] = ($numbersByYear[$year] ?? 0) + 1;
            expect($document->{$numberColumn})->toBe(sprintf('%s-%s-%05d', $prefix, $year, $numbersByYear[$year]));
        }
    }

    expect(Order::where('status', OrderStatus::Completed)->count())->toBeGreaterThan(0)
        ->and(Order::where('status', OrderStatus::Processing)->count())->toBe(1)
        ->and(Order::where('status', OrderStatus::ReadyForCollection)->count())->toBe(1)
        ->and(Order::where('status', OrderStatus::Quoted)->count())->toBe(2)
        ->and(Order::where('status', OrderStatus::Expired)->count())->toBe(1);
    $cancelled = Order::where('status', OrderStatus::Cancelled)->sole();
    expect($cancelled->needs_attention)->toBeTrue()
        ->and($cancelled->cancel_reason)->toBe(LateCancelReason::DeliveryRefused->value);

    $acceptedQuotes = Order::where('source', OrderSource::Quote)->whereNotNull('accepted_at')->with('invoices')->get()->keyBy('status');
    $processing = $acceptedQuotes->get(OrderStatus::Processing->value);
    $completed = $acceptedQuotes->get(OrderStatus::Completed->value);
    $collection = $acceptedQuotes->get(OrderStatus::ReadyForCollection->value);
    expect($acceptedQuotes)->toHaveCount(3)
        ->and($processing->balanceCents())->toBeGreaterThan(0)
        ->and($processing->paid_cents)->toBeGreaterThan(0)
        ->and($completed->balanceCents())->toBe(0)
        ->and($completed->invoices)->toHaveCount(1)
        ->and($collection->balanceCents())->toBe(0)
        ->and($collection->invoices)->toHaveCount(1);

    $return = SalesReturn::sole();
    expect($return->lines()->where('restock', true)->sole()->qty)->toBe(1)
        ->and($return->lines()->where('restock', false)->sole()->qty)->toBe(1)
        ->and(Payment::where('sales_return_id', $return->id)->where('kind', PaymentKind::Refund)->sole()->amount_cents)->toBe($return->total_cents)
        ->and(CreditNote::where('sales_return_id', $return->id)->count())->toBe(1);

    $this->travelTo($startedAt);
    $dashboard = app(DashboardData::class);
    $expiringSoon = $dashboard->quotesExpiringSoon();
    $today = $dashboard->today();

    expect($today['orders_count'])->toBe(3)
        ->and($today['net_cents'])->toBeGreaterThan(0)
        ->and($today['payments_cents'])->toBeGreaterThan(0)
        ->and($dashboard->moneyOwed()['customers_count'])->toBe(3)
        ->and($dashboard->needsAttention())->toBe(1)
        ->and(array_filter($dashboard->lowStock(), fn (array $item): bool => $item['qty'] < $item['reorder_level']))->toHaveCount(2)
        ->and($expiringSoon)->toHaveCount(1);
    $expiry = CarbonImmutable::parse($expiringSoon[0]['valid_until'], config('app.timezone'));
    expect($expiry->greaterThan($startedAt) && $expiry->lessThanOrEqualTo($startedAt->addDays(2)))->toBeTrue();

    foreach (Order::whereNotNull('confirmed_at')->get() as $order) {
        $confirmedAt = $order->confirmed_at->copy()->setTimezone('Asia/Colombo');
        expect($confirmedAt->betweenIncluded($startedAt->subDays(30), $startedAt))->toBeTrue()
            ->and($confirmedAt->format('H:i:s'))->toBeGreaterThanOrEqual('09:00:00')->toBeLessThanOrEqual('18:00:00');
    }
    $this->travelBack();
});

it('keeps recent sales in the past when the demo is seeded before opening', function () {
    $this->seed(DatabaseSeeder::class);
    $startedAt = CarbonImmutable::parse('2027-01-10 08:00:00', 'Asia/Colombo');
    $this->travelTo($startedAt);

    $this->seed(DemoDataSeeder::class);

    $latestSale = Order::where('source', OrderSource::Counter)->latest('confirmed_at')->firstOrFail();
    expect($latestSale->confirmed_at->copy()->setTimezone('Asia/Colombo')->toDateString())->toBe($startedAt->subDay()->toDateString())
        ->and(Carbon::getTestNow())->toBeNull();
});

it('refuses a second run without changing the seeded history', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoDataSeeder::class);
    $snapshot = fn (): array => collect([
        'activity_log', 'categories', 'credit_notes', 'customers', 'document_sequences', 'goods_receipt_lines',
        'goods_receipts', 'invoices', 'order_events', 'order_lines', 'orders', 'payments', 'products',
        'quote_snapshots', 'sales_return_lines', 'sales_returns', 'stock_levels', 'stock_movements', 'suppliers', 'variants',
    ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->all()])->all();
    $before = $snapshot();

    expect(fn () => $this->seed(DemoDataSeeder::class))
        ->toThrow(RuntimeException::class, 'Demo data can only be added to a database with no sales yet.');

    expect($snapshot())->toEqual($before)
        ->and(Carbon::getTestNow())->toBeNull();
});

it('refuses to seed in production even with force', function () {
    $this->app->instance('env', 'production');
    $this->travelTo(CarbonImmutable::parse('2027-01-10 13:30:00', 'Asia/Colombo'));

    expect(fn () => $this->artisan('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true])->run())
        ->toThrow(RuntimeException::class, 'Demo data can only be seeded in local, testing, or staging.');

    expect(Product::count())->toBe(0)
        ->and(Carbon::getTestNow())->toBeNull();
});

it('requires an active admin from the base seed', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::sole();
    $admin->forceFill(['is_active' => false])->save();
    expect($admin->refresh()->is_active)->toBeFalse();

    expect(fn () => $this->seed(DemoDataSeeder::class))
        ->toThrow(RuntimeException::class, 'Run the base seed first: php artisan db:seed --force');

    expect(Order::count())->toBe(0)
        ->and(Carbon::getTestNow())->toBeNull();
});

it('rolls back the demo and clears both clocks when an action fails', function () {
    $this->seed(DatabaseSeeder::class);
    Location::default()->update(['is_active' => false]);

    expect(fn () => $this->seed(DemoDataSeeder::class))->toThrow(ValidationException::class, 'Choose an active location.');

    expect(Carbon::getTestNow())->toBeNull()
        ->and(CarbonImmutable::getTestNow())->toBeNull()
        ->and(Product::where('slug', 'like', 'demo-%')->count())->toBe(0)
        ->and(Customer::where('is_walk_in', false)->count())->toBe(0)
        ->and(Supplier::count())->toBe(0)
        ->and(GoodsReceipt::count())->toBe(0)
        ->and(Order::count())->toBe(0);
});
