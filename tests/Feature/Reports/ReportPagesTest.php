<?php

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\GoodsReceivedBySupplier;
use App\Filament\Pages\Reports\MoneyOwed;
use App\Filament\Pages\Reports\SalesByDay;
use App\Filament\Pages\Reports\SalesByProduct;
use App\Filament\Pages\Reports\StockValue;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Asia/Colombo'));
});

it('renders every report for an admin', function (string $page) {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());

    $this->get($page::getUrl())->assertOk();
    Livewire::test($page)->assertOk()->assertSee('Totals')->assertActionExists('exportCsv');
})->with([SalesByDay::class, SalesByProduct::class, StockValue::class, MoneyOwed::class, GoodsReceivedBySupplier::class]);

it('restricts the report gate to active administrators', function (UserRole $role, bool $active, bool $allowed) {
    $user = User::factory()->create(['role' => $role, 'is_active' => $active]);

    expect(Gate::forUser($user)->allows('viewReports'))->toBe($allowed);
})->with([
    [UserRole::Admin, true, true],
    [UserRole::Admin, false, false],
    [UserRole::Sales, true, false],
    [UserRole::Stock, true, false],
]);

it('hides and forbids all reports for non-administrators', function (string $page, UserRole $role) {
    $this->actingAs(User::factory()->withAppAuthentication()->create(['role' => $role]));

    expect($page::canAccess())->toBeFalse();
    $this->get($page::getUrl())->assertForbidden();
    Livewire::test($page)->assertForbidden();
})->with([SalesByDay::class, SalesByProduct::class, StockValue::class, MoneyOwed::class, GoodsReceivedBySupplier::class])
    ->with([UserRole::Sales, UserRole::Stock]);

it('defaults the range to the Colombo month and applies an inclusive range', function () {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'confirmed_at' => '2026-11-01 00:00:00', 'total_cents' => 12345]);
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'confirmed_at' => '2026-11-30 23:59:59', 'total_cents' => 22200]);
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'confirmed_at' => '2026-12-01 00:00:00', 'total_cents' => 99999]);

    $page = Livewire::test(SalesByDay::class)->assertSet('data.from', '2026-11-01')->assertSet('data.to', '2026-11-30')
        ->assertSee('Rs 345.45');
    $page->fillForm(['from' => '2026-11-01', 'to' => '2026-11-01'])->call('applyFilters')->assertHasNoFormErrors()
        ->assertSet('appliedRange', ['from' => '2026-11-01', 'to' => '2026-11-01'])->assertSee('Rs 123.45')->assertDontSee('Rs 222.00');
});

it('refuses invalid report ranges before applying them', function (string $from, string $to, string $field) {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());

    Livewire::test(SalesByDay::class)->fillForm(['from' => $from, 'to' => $to])->call('applyFilters')
        ->assertHasFormErrors([$field])->assertSet('appliedRange', ['from' => '2026-11-01', 'to' => '2026-11-30']);
})->with([
    ['2026-11-02', '2026-11-01', 'to'],
    ['2025-01-01', '2026-01-02', 'to'],
    ['', '2026-11-01', 'from'],
]);

it('accepts exactly 366 inclusive days and exports the selected range', function () {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());

    Livewire::test(SalesByDay::class)->fillForm(['from' => '2025-01-01', 'to' => '2026-01-01'])
        ->call('applyFilters')->assertHasNoFormErrors();
    Livewire::test(SalesByDay::class)->fillForm(['from' => '2026-11-01', 'to' => '2026-11-30'])
        ->callAction('exportCsv')->assertHasNoActionErrors()->assertFileDownloaded('sales-by-day-2026-11-01-to-2026-11-30.csv');
});

it('exports the applied range even when the edited form has not been applied', function () {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'confirmed_at' => '2026-11-01 12:00:00', 'total_cents' => 12345]);
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'confirmed_at' => '2026-12-01 12:00:00', 'total_cents' => 99999]);

    $page = Livewire::test(SalesByDay::class)
        ->fillForm(['from' => '2026-11-01', 'to' => '2026-11-01'])->call('applyFilters')
        ->fillForm(['from' => '2026-12-01', 'to' => '2026-12-31'])
        ->callAction('exportCsv')->assertHasNoActionErrors()
        ->assertFileDownloaded('sales-by-day-2026-11-01-to-2026-11-01.csv')
        ->assertSet('appliedRange', ['from' => '2026-11-01', 'to' => '2026-11-01'])
        ->assertSee('Rs 123.45')->assertDontSee('Rs 999.99');

    $csv = base64_decode($page->effects['download']['content']);
    expect($csv)->toContain('2026-11-01', '123.45')->not->toContain('2026-12-01', '999.99');
});

it('exports the applied range even when the unsaved form is invalid', function () {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());

    Livewire::test(SalesByDay::class)->fillForm(['from' => '', 'to' => '2025-01-01'])
        ->call('exportCsv')->assertHasNoFormErrors()
        ->assertFileDownloaded('sales-by-day-2026-11-01-to-2026-11-30.csv');
});

it('rechecks current database permissions on every request despite a stale authenticated user', function (string $method) {
    $admin = User::factory()->admin()->withAppAuthentication()->create();
    $this->actingAs($admin);
    $page = Livewire::test(SalesByDay::class);
    User::query()->whereKey($admin->id)->update(['role' => UserRole::Sales->value]);
    expect($admin->role)->toBe(UserRole::Admin);
    expect($admin->fresh()?->role)->toBe(UserRole::Sales);
    expect(Gate::forUser($admin)->allows('viewReports'))->toBeFalse();

    $page->call($method)->assertForbidden();
})->with(['exportCsv', 'applyFilters', 'getReport', 'getTotalsSummary', 'getTableRecords', 'usesDateRange', 'reportNote', '$refresh']);

it('forbids an open report after its user is deactivated or deleted', function (string $change) {
    $admin = User::factory()->admin()->withAppAuthentication()->create();
    $this->actingAs($admin);
    $page = Livewire::test(SalesByDay::class);
    if ($change === 'deactivated') {
        User::query()->whereKey($admin->id)->update(['is_active' => false]);
    } else {
        User::query()->whereKey($admin->id)->delete();
    }

    $page->call('exportCsv')->assertForbidden();
})->with(['deactivated', 'deleted']);
