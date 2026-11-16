<?php

use App\Enums\StockReason;
use App\Enums\UserRole;
use App\Filament\Resources\Stock\Pages\ListStock;
use App\Filament\Resources\Stock\Pages\ViewStock;
use App\Filament\Resources\Stock\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\Stock\StockResource;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\User;
use App\Models\Variant;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('lets every active staff role view tracked stock and its history', function (UserRole $role) {
    $this->actingAs(User::factory()->withAppAuthentication()->create(['role' => $role]));
    Location::factory()->create(['is_default' => true]);
    $tracked = Variant::factory()->create();
    $untracked = Variant::factory()->create(['track_stock' => false]);

    $this->get(StockResource::getUrl())->assertOk();
    $this->get(StockResource::getUrl('view', ['record' => $tracked]))->assertOk();
    Livewire::test(ListStock::class)->assertCanSeeTableRecords([$tracked])->assertCanNotSeeTableRecords([$untracked]);
})->with([UserRole::Admin, UserRole::Sales, UserRole::Stock]);

it('filters low and out of stock using zero for missing levels and the default location', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $location = Location::factory()->create(['is_default' => true]);
    $low = Variant::factory()->create(['reorder_level' => 5]);
    $out = Variant::factory()->create(['reorder_level' => 0]);
    $healthy = Variant::factory()->create(['reorder_level' => 2]);
    $noReorder = Variant::factory()->create(['reorder_level' => null]);
    StockLevel::factory()->create(['variant_id' => $low->id, 'location_id' => $location->id, 'qty' => 5]);
    StockLevel::factory()->create(['variant_id' => $healthy->id, 'location_id' => $location->id, 'qty' => 3]);
    StockLevel::factory()->create(['variant_id' => $out->id, 'qty' => 100]);

    Livewire::test(ListStock::class)->filterTable('low_stock')->assertCanSeeTableRecords([$low, $out])
        ->assertCanNotSeeTableRecords([$healthy, $noReorder]);
    Livewire::test(ListStock::class)->filterTable('out_of_stock')->assertCanSeeTableRecords([$out, $noReorder])
        ->assertCanNotSeeTableRecords([$low, $healthy]);
    expect(StockResource::getNavigationBadge())->toBe('2');
});

it('searches stock by SKU or English product title and filters active items', function () {
    $this->actingAs(User::factory()->sales()->withAppAuthentication()->create());
    Location::factory()->create(['is_default' => true]);
    $product = Product::factory()->create(['title' => ['en' => 'Unique ceiling panel']]);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'UNIQUE-STOCK']);
    $inactive = Variant::factory()->create(['is_active' => false]);

    Livewire::test(ListStock::class)->searchTable('UNIQUE-STOCK')->assertCanSeeTableRecords([$variant])->assertCanNotSeeTableRecords([$inactive]);
    Livewire::test(ListStock::class)->searchTable('Unique ceiling')->assertCanSeeTableRecords([$variant])->assertCanNotSeeTableRecords([$inactive]);
    Livewire::test(ListStock::class)->filterTable('is_active', false)->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$variant]);
});

it('lets stock and admin adjust from the table and view header', function (UserRole $role) {
    $this->actingAs(User::factory()->withAppAuthentication()->create(['role' => $role]));
    $location = Location::factory()->create(['is_default' => true]);
    $variant = Variant::factory()->create();

    Livewire::test(ListStock::class)->assertActionVisible(TestAction::make('adjust')->table($variant))
        ->callAction(TestAction::make('adjust')->table($variant), data: ['delta' => 7, 'note' => 'Opening stock'])
        ->assertHasNoActionErrors()->assertNotified('Stock adjusted to 7');
    Livewire::test(ViewStock::class, ['record' => $variant->id])->assertActionVisible('adjust')
        ->callAction('adjust', data: ['delta' => -2, 'note' => 'Damage'])
        ->assertHasNoActionErrors()->assertNotified('Stock adjusted to 5');

    $this->assertDatabaseHas('stock_levels', ['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 5]);
    expect(StockMovement::query()->where('variant_id', $variant->id)->count())->toBe(2);
})->with([UserRole::Admin, UserRole::Stock]);

it('hides adjustment from sales on both stock pages', function () {
    $this->actingAs(User::factory()->sales()->withAppAuthentication()->create());
    Location::factory()->create(['is_default' => true]);
    $variant = Variant::factory()->create();

    Livewire::test(ListStock::class)->assertActionHidden(TestAction::make('adjust')->table($variant));
    Livewire::test(ViewStock::class, ['record' => $variant->id])->assertActionHidden('adjust');
    expect(StockResource::canCreate())->toBeFalse();
    expect(StockResource::canEdit($variant))->toBeFalse();
    expect(StockResource::canDelete($variant))->toBeFalse();
});

it('surfaces insufficient stock on the delta form field without changing stock', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $location = Location::factory()->create(['is_default' => true]);
    $variant = Variant::factory()->create();
    StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 2]);

    Livewire::test(ListStock::class)->callAction(TestAction::make('adjust')->table($variant), data: ['delta' => -3, 'note' => 'Damage'])
        ->assertHasActionErrors(['delta' => 'Only 2 in stock; you can remove at most 2.']);

    $this->assertDatabaseHas('stock_levels', ['variant_id' => $variant->id, 'qty' => 2]);
    expect(StockMovement::count())->toBe(0);
});

it('validates zero adjustment and required reasons in the stock action', function (array $data, string $field) {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    Location::factory()->create(['is_default' => true]);
    $variant = Variant::factory()->create();

    Livewire::test(ListStock::class)->callAction(TestAction::make('adjust')->table($variant), data: $data)->assertHasActionErrors([$field]);

    expect(StockMovement::count())->toBe(0);
})->with([
    [['delta' => 0, 'note' => 'Count'], 'delta'],
    [['delta' => 1, 'note' => ''], 'note'],
]);

it('shows read only movements with source reason actor and signed changes', function () {
    $actor = User::factory()->sales()->withAppAuthentication()->create();
    $this->actingAs($actor);
    $location = Location::factory()->create(['is_default' => true]);
    $variant = Variant::factory()->create();
    $stocktake = Stocktake::factory()->create(['location_id' => $location->id]);
    $movement = StockMovement::factory()->create([
        'variant_id' => $variant->id, 'location_id' => $location->id, 'delta' => 5, 'qty_after' => 5,
        'reason' => StockReason::Stocktake, 'source_type' => $stocktake->getMorphClass(), 'source_id' => $stocktake->id,
        'user_id' => $actor->id, 'note' => 'Opening inventory',
    ]);

    Livewire::test(ViewStock::class, ['record' => $variant->id])->assertSeeLivewire(MovementsRelationManager::class);
    Livewire::test(MovementsRelationManager::class, ['ownerRecord' => $variant, 'pageClass' => ViewStock::class])
        ->assertCanSeeTableRecords([$movement])->assertSee('+5')->assertSee('Stocktake #'.$stocktake->id)
        ->assertSee('Opening inventory')->assertSee($actor->name)
        ->assertActionDoesNotExist(TestAction::make('create')->table())
        ->assertActionDoesNotExist(TestAction::make('edit')->table($movement))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($movement));
});

it('returns no badge without a default location so other admin pages remain accessible', function () {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());

    expect(StockResource::getNavigationBadge())->toBeNull();
});

it('shows stock limit validation on the adjustment delta field', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $location = Location::factory()->create(['is_default' => true]);
    $variant = Variant::factory()->create();
    StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 2147483647]);

    Livewire::test(ListStock::class)
        ->callAction(TestAction::make('adjust')->table($variant), data: ['delta' => 1, 'note' => 'More stock'])
        ->assertHasActionErrors(['delta' => 'That would exceed the maximum stock quantity.']);

    $this->assertDatabaseHas('stock_levels', ['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 2147483647]);
    $this->assertDatabaseCount('stock_movements', 0);
});
