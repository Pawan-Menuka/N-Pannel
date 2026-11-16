<?php

use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\SaveGoodsReceiptLine;
use App\Actions\Purchasing\VoidGoodsReceipt;
use App\Actions\Stock\AdjustStock;
use App\Enums\GoodsReceiptStatus;
use App\Enums\UserRole;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\EditGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Filament\Resources\GoodsReceipts\Pages\ViewGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\RelationManagers\LinesRelationManager;
use App\Filament\Resources\Stock\Pages\ViewStock;
use App\Filament\Resources\Stock\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\GoodsReceiptsRelationManager;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Variant;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Location::factory()->create(['is_default' => true]);
    $this->actor = User::factory()->stock()->withAppAuthentication()->create();
    $this->actingAs($this->actor);
});

it('creates and edits suppliers with normalized phones and visible validation errors', function () {
    Livewire::test(CreateSupplier::class)->fillForm(['name' => 'Supplier', 'phone_e164' => '077 123 4567', 'is_active' => true])
        ->call('create')->assertHasNoFormErrors();
    $supplier = Supplier::sole();
    expect($supplier->phone_e164)->toBe('+94771234567');
    Livewire::test(EditSupplier::class, ['record' => $supplier->id])->fillForm(['phone_e164' => 'bad phone'])->call('save')
        ->assertHasFormErrors(['phone_e164']);
    expect($supplier->fresh()->phone_e164)->toBe('+94771234567');
    Livewire::test(CreateSupplier::class)->fillForm(['name' => 'Bad phone', 'phone_e164' => '123'])
        ->call('create')->assertHasFormErrors(['phone_e164']);
    $this->assertDatabaseCount('suppliers', 1);
    Livewire::test(ListSuppliers::class)->searchTable('Supplier')->assertCanSeeTableRecords([$supplier]);
});

it('refuses supplier deletion with a useful notification and shows its receipts read only', function () {
    $supplier = Supplier::factory()->create();
    $receipt = GoodsReceipt::factory()->create(['supplier_id' => $supplier->id]);
    Livewire::test(EditSupplier::class, ['record' => $supplier->id])->callAction('delete')
        ->assertNotified('This supplier has goods receipts. Deactivate the supplier instead.');
    $this->assertModelExists($supplier);
    Livewire::test(GoodsReceiptsRelationManager::class, ['ownerRecord' => $supplier, 'pageClass' => ViewSupplier::class])
        ->assertCanSeeTableRecords([$receipt]);
});

it('creates a receipt at Main and redirects to its draft edit page', function () {
    $supplier = Supplier::factory()->create();
    $page = Livewire::test(CreateGoodsReceipt::class)->fillForm([
        'supplier_id' => $supplier->id, 'received_on' => now('Asia/Colombo')->toDateString(), 'supplier_reference' => 'DEL-1',
    ])->call('create')->assertHasNoFormErrors();
    $receipt = GoodsReceipt::sole();
    $page->assertRedirect(GoodsReceiptResource::getUrl('edit', ['record' => $receipt]));
    expect($receipt->location_id)->toBe(Location::default()->id);
    expect($receipt->created_by_id)->toBe($this->actor->id);
    expect($receipt->status)->toBe(GoodsReceiptStatus::Draft);
    Livewire::test(EditGoodsReceipt::class, ['record' => $receipt->id])->fillForm(['note' => 'Checked delivery'])->call('save')->assertHasNoFormErrors();
    expect($receipt->fresh()->note)->toBe('Checked delivery');
});

it('adds edits and deletes receipt lines through the relation manager using rupee inputs', function () {
    $receipt = GoodsReceipt::factory()->create();
    $variant = Variant::factory()->create(['last_cost_cents' => 12345]);
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $receipt, 'pageClass' => EditGoodsReceipt::class])
        ->callAction(TestAction::make('create')->table(), data: ['variant_id' => $variant->id, 'qty' => 2, 'unit_cost_cents' => '123.45'])
        ->assertHasNoActionErrors();
    $line = $receipt->lines()->sole();
    expect($line->unit_cost_cents)->toBe(12345);
    expect($receipt->fresh()->total_cost_cents)->toBe(24690);
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $receipt, 'pageClass' => EditGoodsReceipt::class])
        ->callAction(TestAction::make('edit')->table($line), data: ['variant_id' => $variant->id, 'qty' => 3, 'unit_cost_cents' => '150.50'])
        ->assertHasNoActionErrors();
    expect($receipt->fresh()->total_cost_cents)->toBe(45150);
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $receipt, 'pageClass' => EditGoodsReceipt::class])
        ->callAction(TestAction::make('delete')->table($line))->assertHasNoActionErrors();
    expect($receipt->fresh()->total_cost_cents)->toBe(0);
});

it('prefills the most recent unit cost when an item is selected', function () {
    $receipt = GoodsReceipt::factory()->create();
    $variant = Variant::factory()->create(['last_cost_cents' => 12345]);
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $receipt, 'pageClass' => EditGoodsReceipt::class])
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['variant_id' => $variant->id])
        ->assertSchemaStateSet(['unit_cost_cents' => '123.45']);
});

it('shows duplicate and cost bound errors from the line action', function () {
    $receipt = GoodsReceipt::factory()->create();
    $variant = Variant::factory()->create();
    app(SaveGoodsReceiptLine::class)->handle($receipt, null, $variant->id, 1, 100, $this->actor);
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $receipt, 'pageClass' => EditGoodsReceipt::class])
        ->callAction(TestAction::make('create')->table(), data: ['variant_id' => $variant->id, 'qty' => 1, 'unit_cost_cents' => '1.00'])
        ->assertHasActionErrors(['variant_id']);
    $other = Variant::factory()->create();
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $receipt, 'pageClass' => EditGoodsReceipt::class])
        ->callAction(TestAction::make('create')->table(), data: ['variant_id' => $other->id, 'qty' => 1, 'unit_cost_cents' => '100,000,000.01'])
        ->assertHasActionErrors(['unit_cost_cents']);
    expect($receipt->lines()->count())->toBe(1);
});

it('posts from the header displays a GRN number and refuses the posted edit route', function () {
    $receipt = GoodsReceipt::factory()->create();
    $variant = Variant::factory()->create();
    app(SaveGoodsReceiptLine::class)->handle($receipt, null, $variant->id, 50, 100, $this->actor);
    Livewire::test(EditGoodsReceipt::class, ['record' => $receipt->id])->callAction('post')->assertNotified('Goods received posted');
    expect($receipt->fresh()->number)->toStartWith('GRN-');
    expect($receipt->fresh()->status)->toBe(GoodsReceiptStatus::Posted);
    $this->get(GoodsReceiptResource::getUrl('edit', ['record' => $receipt]))->assertForbidden();
    Livewire::test(ViewGoodsReceipt::class, ['record' => $receipt->id])->assertSee($receipt->fresh()->number);
    Livewire::test(ListGoodsReceipts::class)->assertCanSeeTableRecords([$receipt]);
    $movement = StockMovement::sole();
    Livewire::test(MovementsRelationManager::class, ['ownerRecord' => $variant, 'pageClass' => ViewStock::class])
        ->assertTableColumnStateSet('source_label', $receipt->fresh()->number, $movement);
});

it('shows the line count units and cost in the posting confirmation', function () {
    $receipt = GoodsReceipt::factory()->create();
    app(SaveGoodsReceiptLine::class)->handle($receipt, null, Variant::factory()->create()->id, 50, 100, $this->actor);
    $page = Livewire::test(EditGoodsReceipt::class, ['record' => $receipt->id])->mountAction('post');
    expect($page->instance()->getMountedAction()->getModalDescription())->toContain('1 line', '50 units', 'Rs 50.00');
});

it('voids from the view header with a required reason and shows shortage refusals', function () {
    $receipt = GoodsReceipt::factory()->create(['location_id' => Location::default()->id]);
    $variant = Variant::factory()->create(['sku' => 'GYP-VOID-UI']);
    app(SaveGoodsReceiptLine::class)->handle($receipt, null, $variant->id, 50, 100, $this->actor);
    app(PostGoodsReceipt::class)->handle($receipt, $this->actor);
    Livewire::test(ViewGoodsReceipt::class, ['record' => $receipt->id])->callAction('void', data: ['reason' => ''])->assertHasActionErrors(['reason' => 'required']);
    app(AdjustStock::class)->handle($variant, -30, 'Sold', $this->actor);
    Livewire::test(ViewGoodsReceipt::class, ['record' => $receipt->id])->callAction('void', data: ['reason' => 'Mistake'])
        ->assertNotified('Cannot void this receipt: GYP-VOID-UI: received 50, in stock 20');
    expect($receipt->fresh()->status)->toBe(GoodsReceiptStatus::Posted);
    app(AdjustStock::class)->handle($variant, 30, 'Returned', $this->actor);
    Livewire::test(ViewGoodsReceipt::class, ['record' => $receipt->id])->callAction('void', data: ['reason' => 'Mistake'])
        ->assertNotified('Goods received voided');
    expect($receipt->fresh()->status)->toBe(GoodsReceiptStatus::Void);
    $this->get(GoodsReceiptResource::getUrl('edit', ['record' => $receipt]))->assertForbidden();
});

it('lets admins explicitly override a shortage from the void form', function () {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    $receipt = GoodsReceipt::factory()->create(['location_id' => Location::default()->id]);
    $variant = Variant::factory()->create();
    app(SaveGoodsReceiptLine::class)->handle($receipt, null, $variant->id, 2, 100, $this->actor);
    app(PostGoodsReceipt::class)->handle($receipt, $this->actor);
    app(AdjustStock::class)->handle($variant, -2, 'Sold', $this->actor);
    Livewire::test(ViewGoodsReceipt::class, ['record' => $receipt->id])
        ->callAction('void', data: ['reason' => 'Approved', 'override' => true])->assertNotified('Goods received voided');
    expect(StockMovement::query()->where('reason', 'goods_received_void')->sole()->is_override)->toBeTrue();
});

it('keeps a posted receipt lines manager read only even when mounted for the edit page', function () {
    $receipt = GoodsReceipt::factory()->create();
    $line = app(SaveGoodsReceiptLine::class)->handle($receipt, null, Variant::factory()->create()->id, 1, 100, $this->actor);
    app(PostGoodsReceipt::class)->handle($receipt, $this->actor);
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $receipt->fresh(), 'pageClass' => EditGoodsReceipt::class])
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($line))
        ->assertActionHidden(TestAction::make('delete')->table($line));
});

it('reports stale post actions from another mounted edit page as notifications', function () {
    $receipt = GoodsReceipt::factory()->create();
    app(SaveGoodsReceiptLine::class)->handle($receipt, null, Variant::factory()->create()->id, 1, 100, $this->actor);
    $first = Livewire::test(EditGoodsReceipt::class, ['record' => $receipt->id]);
    $second = Livewire::test(EditGoodsReceipt::class, ['record' => $receipt->id]);
    $first->callAction('post')->assertNotified('Goods received posted');
    $second->callAction('post')->assertNotified('Only draft goods received notes can be changed.');
    $this->assertDatabaseCount('stock_movements', 1);
});

it('refuses sales staff every supplier and receipt page', function () {
    $this->actingAs(User::factory()->sales()->withAppAuthentication()->create());
    $supplier = Supplier::factory()->create();
    $receipt = GoodsReceipt::factory()->create();
    foreach ([SupplierResource::class => $supplier, GoodsReceiptResource::class => $receipt] as $resource => $record) {
        $this->get($resource::getUrl())->assertForbidden();
        $this->get($resource::getUrl('create'))->assertForbidden();
        $this->get($resource::getUrl('view', ['record' => $record]))->assertForbidden();
        $this->get($resource::getUrl('edit', ['record' => $record]))->assertForbidden();
    }
});

it('allows stock and admin staff to view and create purchasing documents', function (UserRole $role) {
    $this->actingAs(User::factory()->withAppAuthentication()->create(['role' => $role]));
    $this->get(SupplierResource::getUrl())->assertOk();
    $this->get(SupplierResource::getUrl('create'))->assertOk();
    $this->get(GoodsReceiptResource::getUrl())->assertOk();
    $this->get(GoodsReceiptResource::getUrl('create'))->assertOk();
})->with([UserRole::Admin, UserRole::Stock]);

it('labels the supplier name as Supplier on the receipt view and list', function () {
    $receipt = GoodsReceipt::factory()->create();

    Livewire::test(ViewGoodsReceipt::class, ['record' => $receipt->id])
        ->assertSchemaComponentExists('supplier.name', 'infolist', fn (TextEntry $entry): bool => $entry->getLabel() === 'Supplier');
    Livewire::test(ListGoodsReceipts::class)
        ->assertTableColumnExists('supplier.name', fn (TextColumn $column): bool => $column->getLabel() === 'Supplier');
});

it('notifies instead of saving from an open edit page after a colleague posts or voids the receipt', function (bool $void) {
    $receipt = GoodsReceipt::factory()->create(['note' => 'Original note']);
    app(SaveGoodsReceiptLine::class)->handle($receipt, null, Variant::factory()->create()->id, 1, 100, $this->actor);
    $page = Livewire::test(EditGoodsReceipt::class, ['record' => $receipt->id])->fillForm(['note' => 'Unsaved changes']);
    app(PostGoodsReceipt::class)->handle($receipt, $this->actor);
    if ($void) {
        app(VoidGoodsReceipt::class)->handle($receipt, $this->actor, 'Correction');
    }
    $before = $receipt->fresh()->getAttributes();
    $movementCount = StockMovement::count();

    $page->call('save')->assertNotified('This goods received note has been posted or voided and can no longer be edited.');

    expect($receipt->fresh()->getAttributes())->toBe($before);
    expect(StockMovement::count())->toBe($movementCount);
})->with([false, true]);
