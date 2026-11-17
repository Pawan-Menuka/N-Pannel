<?php

use App\Actions\Reports\DashboardData;
use App\Actions\Reports\SalesByDay;
use App\Domain\Money\Lkr;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\MoneyOwed;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Stock\StockResource;
use App\Filament\Widgets\AcceptedOrdersWithBalance;
use App\Filament\Widgets\LowStock;
use App\Filament\Widgets\MoneyOwedStats;
use App\Filament\Widgets\NeedsAttentionStats;
use App\Filament\Widgets\QuotesExpiringSoon;
use App\Filament\Widgets\TodayStats;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SalesReturn;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Variant;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'Asia/Colombo'));
});

it('shows sales dashboard widgets to active admin and sales users', function (UserRole $role) {
    $this->actingAs(User::factory()->withAppAuthentication()->create(['role' => $role]));

    foreach ([TodayStats::class, MoneyOwedStats::class, NeedsAttentionStats::class, AcceptedOrdersWithBalance::class, QuotesExpiringSoon::class, LowStock::class] as $widget) {
        expect($widget::canView())->toBeTrue();
        Livewire::test($widget)->assertOk();
    }
})->with([UserRole::Admin, UserRole::Sales]);

it('allows stock users only the low stock widget and refuses direct sales widget mounts', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());

    foreach ([TodayStats::class, MoneyOwedStats::class, NeedsAttentionStats::class, AcceptedOrdersWithBalance::class, QuotesExpiringSoon::class] as $widget) {
        expect($widget::canView())->toBeFalse();
        Livewire::test($widget)->assertForbidden();
    }
    expect(LowStock::canView())->toBeTrue();
    Livewire::test(LowStock::class)->assertOk();
});

it('hides every dashboard widget from inactive users and guests', function () {
    foreach ([TodayStats::class, MoneyOwedStats::class, NeedsAttentionStats::class, AcceptedOrdersWithBalance::class, QuotesExpiringSoon::class, LowStock::class] as $widget) {
        expect($widget::canView())->toBeFalse();
    }
    $this->actingAs(User::factory()->admin()->create(['is_active' => false]));
    foreach ([TodayStats::class, MoneyOwedStats::class, NeedsAttentionStats::class, AcceptedOrdersWithBalance::class, QuotesExpiringSoon::class, LowStock::class] as $widget) {
        expect($widget::canView())->toBeFalse();
    }
});

it('uses the same daily figures as the sales report including returns and refunds', function () {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'confirmed_at' => '2026-11-18 00:00:00', 'total_cents' => 10000, 'returned_cents' => 2500]);
    Order::factory()->create(['status' => OrderStatus::Quoted, 'confirmed_at' => null, 'total_cents' => 70000]);
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'confirmed_at' => '2026-11-17 23:59:59', 'total_cents' => 90000]);
    SalesReturn::factory()->for($order)->create(['total_cents' => 2500, 'created_at' => '2026-11-18 11:00:00']);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'amount_cents' => 10000, 'confirmed_at' => '2026-11-18 00:00:00']);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'kind' => PaymentKind::Refund, 'amount_cents' => 2500, 'confirmed_at' => '2026-11-18 10:00:00']);
    $day = CarbonImmutable::parse('2026-11-18', 'Asia/Colombo');

    $today = app(DashboardData::class)->today();
    $report = app(SalesByDay::class)->run($day, $day);

    expect($today)->toBe(['net_cents' => 7500, 'orders_count' => 1, 'payments_cents' => 7500]);
    expect($today['net_cents'])->toBe($report->totals['net_cents']);
    expect($today['orders_count'])->toBe($report->totals['orders_count']);
    expect($today['payments_cents'])->toBe($report->totals['payments_cents']);
    Livewire::test(TodayStats::class)->assertOk()->assertSee('Net sales today')->assertSee('Orders today')->assertSee('Payments received today')->assertSee(Lkr::formatWithCurrency(7500));
});

it('shows current effective debt and attention counts with the admin report link', function () {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create(['status' => OrderStatus::Completed, 'confirmed_at' => now(), 'total_cents' => 20000, 'returned_cents' => 4000, 'paid_cents' => 7000]);
    Order::factory()->for($customer)->create(['status' => OrderStatus::Cancelled, 'total_cents' => 90000, 'needs_attention' => true]);
    Order::factory()->create(['status' => OrderStatus::Expired, 'total_cents' => 90000, 'needs_attention' => true]);

    Livewire::test(MoneyOwedStats::class)->assertOk()->assertSee('Rs 90.00')->assertSee('1 customers owing')->assertSeeHtml(MoneyOwed::getUrl());
    Livewire::test(NeedsAttentionStats::class)->assertOk()->assertSee('Needs attention')->assertSee('2')->assertSeeHtml(e(OrderResource::getUrl('index', ['tab' => 'needs_attention'])));
    $this->actingAs(User::factory()->sales()->withAppAuthentication()->create());
    Livewire::test(MoneyOwedStats::class)->assertOk()->assertDontSeeHtml(MoneyOwed::getUrl());
});

it('lists only the latest ten accepted orders with a balance and masks removed customer names', function () {
    $this->actingAs(User::factory()->sales()->withAppAuthentication()->create());
    $customer = Customer::factory()->create(['name' => 'Private old name', 'anonymised_at' => now()]);
    $removed = Order::factory()->for($customer)->create(['status' => OrderStatus::Confirmed, 'confirmed_at' => '2026-11-18 11:00:00', 'total_cents' => 10000, 'returned_cents' => 2000, 'paid_cents' => 3000]);
    $excluded = Order::factory()->create(['status' => OrderStatus::Cancelled, 'confirmed_at' => now()]);
    $paid = Order::factory()->create(['status' => OrderStatus::Confirmed, 'confirmed_at' => now(), 'total_cents' => 1000, 'returned_cents' => 1000]);
    $old = Order::factory()->create(['status' => OrderStatus::Confirmed, 'confirmed_at' => '2026-11-01']);
    Order::factory()->count(9)->create(['status' => OrderStatus::Processing, 'confirmed_at' => '2026-11-17']);

    $records = app(DashboardData::class)->acceptedWithBalance();

    expect($records)->toHaveCount(10);
    expect($records[0])->toMatchArray(['id' => $removed->id, 'customer' => 'Removed customer', 'balance_cents' => 5000]);
    expect(array_column($records, 'id'))->not->toContain($excluded->id, $paid->id, $old->id);
    Livewire::test(AcceptedOrdersWithBalance::class)->assertOk()->assertSee($removed->reference)->assertSee('Removed customer')->assertSee('Rs 50.00')->assertDontSee('Private old name')->assertDontSee($excluded->reference)->assertSeeHtml(OrderResource::getUrl('view', ['record' => $removed->id]));
});

it('shows quoted orders expiring between now and three days from now', function () {
    $this->actingAs(User::factory()->sales()->withAppAuthentication()->create());
    $near = Order::factory()->create(['valid_until' => '2026-11-21 12:00:00']);
    $past = Order::factory()->create(['valid_until' => '2026-11-18 11:59:59']);
    $later = Order::factory()->create(['valid_until' => '2026-11-21 12:00:01']);
    $accepted = Order::factory()->create(['status' => OrderStatus::Confirmed, 'valid_until' => '2026-11-19']);

    Livewire::test(QuotesExpiringSoon::class)->assertOk()->assertSee($near->reference)->assertDontSee($past->reference)->assertDontSee($later->reference)->assertDontSee($accepted->reference);
});

it('limits expiring quotes to the ten earliest with a stable order', function () {
    $this->actingAs(User::factory()->sales()->withAppAuthentication()->create());
    $quotes = Order::factory()->count(12)->create(['valid_until' => '2026-11-19 12:00:00']);

    $rows = app(DashboardData::class)->quotesExpiringSoon();

    expect(array_column($rows, 'id'))->toBe($quotes->take(10)->modelKeys());
    Livewire::test(QuotesExpiringSoon::class)->assertOk()
        ->assertSee($quotes[0]->reference)->assertSee($quotes[9]->reference)
        ->assertDontSee($quotes[10]->reference)->assertDontSee($quotes[11]->reference);
});

it('disables automatic polling on the dashboard statistics', function (string $widget) {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());

    expect((new ReflectionProperty($widget, 'pollingInterval'))->getValue(app($widget)))->toBeNull();
    Livewire::test($widget)->assertOk()->assertDontSeeHtml('wire:poll');
})->with([TodayStats::class, MoneyOwedStats::class, NeedsAttentionStats::class]);

it('shows low tracked stock at the reorder boundary including zero and negative quantities', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $low = Variant::factory()->create(['reorder_level' => 3]);
    StockLevel::factory()->for($low)->create(['qty' => 3]);
    $negative = Variant::factory()->create(['reorder_level' => 0]);
    StockLevel::factory()->for($negative)->create(['qty' => -2]);
    $missing = Variant::factory()->create(['reorder_level' => 0]);
    $unset = Variant::factory()->create(['reorder_level' => null]);
    $healthy = Variant::factory()->create(['reorder_level' => 2]);
    StockLevel::factory()->for($healthy)->create(['qty' => 3]);
    $untracked = Variant::factory()->create(['track_stock' => false, 'reorder_level' => 99]);
    $inactive = Variant::factory()->create(['is_active' => false, 'reorder_level' => 99]);

    Livewire::test(LowStock::class)->assertOk()->assertSee($low->sku)->assertSee($negative->sku)->assertSee($missing->sku)->assertDontSee($unset->sku)->assertDontSee($healthy->sku)->assertDontSee($untracked->sku)->assertDontSee($inactive->sku);
});

it('limits low stock to the ten largest shortfalls and links to the full filtered stock list', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $variants = [];
    foreach (range(0, 11) as $index) {
        $variants[] = Variant::factory()->create(['sku' => sprintf('LOW-%02d', $index), 'reorder_level' => $index]);
    }
    $tie = Variant::factory()->create(['sku' => 'LOW-11-TIE', 'reorder_level' => 11]);
    $variantIds = array_map(fn (Variant $variant): int => $variant->id, $variants);

    $rows = app(DashboardData::class)->lowStock();

    expect(array_column($rows, 'variant_id'))->toBe([
        $variantIds[11], $tie->id, $variantIds[10], $variantIds[9], $variantIds[8],
        $variantIds[7], $variantIds[6], $variantIds[5], $variantIds[4], $variantIds[3],
    ]);
    $url = StockResource::getUrl('index', ['filters' => ['low_stock' => ['isActive' => true]]]);
    Livewire::test(LowStock::class)->assertOk()->assertSee('View all')->assertSeeHtml(e($url))
        ->assertSee($variants[11]->sku)->assertSee($tie->sku)->assertDontSee($variants[2]->sku);
});
