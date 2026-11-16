<?php

use App\Enums\StocktakeStatus;
use App\Filament\Resources\Stocktakes\Pages\CreateStocktake;
use App\Filament\Resources\Stocktakes\Pages\EditStocktake;
use App\Filament\Resources\Stocktakes\Pages\ListStocktakes;
use App\Filament\Resources\Stocktakes\Pages\ViewStocktake;
use App\Filament\Resources\Stocktakes\RelationManagers\LinesRelationManager;
use App\Filament\Resources\Stocktakes\StocktakeResource;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Models\Variant;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Location::factory()->create(['is_default' => true]);
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
});

it('creates a stocktake and edits its draft note through the admin pages', function () {
    Livewire::test(CreateStocktake::class)->fillForm(['note' => 'Opening stock'])->call('create')->assertHasNoFormErrors();
    $stocktake = Stocktake::sole();

    expect($stocktake->location_id)->toBe(Location::default()->id);
    expect($stocktake->created_by_id)->toBe(auth()->id());
    Livewire::test(EditStocktake::class, ['record' => $stocktake->id])->fillForm(['note' => 'Counted today'])->call('save')->assertHasNoFormErrors();
    expect($stocktake->fresh()->note)->toBe('Counted today');
    Livewire::test(ListStocktakes::class)->assertCanSeeTableRecords([$stocktake]);
});

it('creates and updates a draft count through its relation manager', function () {
    $stocktake = Stocktake::factory()->create();
    $variant = Variant::factory()->create();

    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $stocktake, 'pageClass' => EditStocktake::class])
        ->callAction(TestAction::make('create')->table(), data: ['variant_id' => $variant->id, 'counted_qty' => 12])
        ->assertHasNoActionErrors();
    $line = $stocktake->lines()->sole();
    expect($line->counted_qty)->toBe(12);
    expect($line->expected_qty)->toBeNull();
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $stocktake, 'pageClass' => EditStocktake::class])
        ->callAction(TestAction::make('edit')->table($line), data: ['counted_qty' => 8])->assertHasNoActionErrors();
    expect($line->fresh()->counted_qty)->toBe(8);
});

it('validates counted quantities in the relation manager', function () {
    $stocktake = Stocktake::factory()->create();
    $variant = Variant::factory()->create();

    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $stocktake, 'pageClass' => EditStocktake::class])
        ->callAction(TestAction::make('create')->table(), data: ['variant_id' => $variant->id, 'counted_qty' => -1])
        ->assertHasActionErrors(['counted_qty']);
    expect($stocktake->lines()->count())->toBe(0);
});

it('deletes a counted line from a draft without changing stock', function () {
    $stocktake = Stocktake::factory()->create();
    $line = StocktakeLine::factory()->create(['stocktake_id' => $stocktake->id]);

    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $stocktake, 'pageClass' => EditStocktake::class])
        ->callAction(TestAction::make('delete')->table($line))->assertHasNoActionErrors();

    $this->assertModelMissing($line);
    expect($stocktake->lines()->count())->toBe(0);
    expect(StockMovement::count())->toBe(0);
});

it('posts a count from the header and redirects to its read-only page', function () {
    $stocktake = Stocktake::factory()->create();
    $line = StocktakeLine::factory()->create(['stocktake_id' => $stocktake->id, 'counted_qty' => 7]);

    Livewire::test(EditStocktake::class, ['record' => $stocktake->id])->callAction('post')->assertNotified('Stocktake posted')
        ->assertRedirect(StocktakeResource::getUrl('view', ['record' => $stocktake]));

    expect($stocktake->fresh()->status)->toBe(StocktakeStatus::Posted);
    expect(StockLevel::query()->where('variant_id', $line->variant_id)->where('location_id', $stocktake->location_id)->sole()->qty)->toBe(7);
    expect(StockMovement::query()->where('variant_id', $line->variant_id)->sole()->source->is($stocktake))->toBeTrue();
    $this->get(StocktakeResource::getUrl('edit', ['record' => $stocktake]))->assertForbidden();
    Livewire::test(ViewStocktake::class, ['record' => $stocktake->id])->assertActionHidden('edit');
});

it('shows a useful notification when posting an empty count', function () {
    $stocktake = Stocktake::factory()->create();

    Livewire::test(EditStocktake::class, ['record' => $stocktake->id])->callAction('post')->assertNotified('Enter at least one count.');
    expect($stocktake->fresh()->status)->toBe(StocktakeStatus::Draft);
    expect(StockMovement::count())->toBe(0);
});

it('hides every count mutation on posted and cancelled parents and denies their edit route', function (StocktakeStatus $status) {
    $stocktake = Stocktake::factory()->create(['status' => $status, 'note' => 'Original']);
    $line = StocktakeLine::factory()->create(['stocktake_id' => $stocktake->id]);

    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $stocktake, 'pageClass' => EditStocktake::class])
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($line))
        ->assertActionHidden(TestAction::make('delete')->table($line));
    $this->get(StocktakeResource::getUrl('edit', ['record' => $stocktake]))->assertForbidden();
    Livewire::test(ViewStocktake::class, ['record' => $stocktake->id])->assertActionHidden('edit');
    expect($stocktake->fresh()->note)->toBe('Original');
})->with([StocktakeStatus::Posted, StocktakeStatus::Cancelled]);

it('cancels a draft without changing stock', function () {
    $stocktake = Stocktake::factory()->create();
    StocktakeLine::factory()->create(['stocktake_id' => $stocktake->id]);

    Livewire::test(EditStocktake::class, ['record' => $stocktake->id])->callAction('cancelStocktake')->assertNotified('Stocktake cancelled');

    expect($stocktake->fresh()->status)->toBe(StocktakeStatus::Cancelled);
    expect(StockMovement::count())->toBe(0);
});

it('refuses already-mounted line mutations after another request posts the parent', function (string $action) {
    $stocktake = Stocktake::factory()->create();
    $line = StocktakeLine::factory()->create(['stocktake_id' => $stocktake->id, 'counted_qty' => 10]);
    $variant = Variant::factory()->create();
    $testAction = $action === 'create' ? TestAction::make($action)->table() : TestAction::make($action)->table($line);
    $page = Livewire::test(LinesRelationManager::class, ['ownerRecord' => $stocktake, 'pageClass' => EditStocktake::class])->mountAction($testAction);
    if ($action !== 'delete') {
        $page->fillForm(['variant_id' => $variant->id, 'counted_qty' => 99]);
    }
    $stocktake->update(['status' => StocktakeStatus::Posted]);

    $page->callMountedAction();

    expect($line->fresh()->counted_qty)->toBe(10);
    expect($stocktake->lines()->count())->toBe(1);
})->with(['create', 'edit', 'delete']);

it('adds tracked variants from the header', function () {
    $stocktake = Stocktake::factory()->create();
    Variant::factory()->count(2)->create();

    Livewire::test(EditStocktake::class, ['record' => $stocktake->id])->callAction('addAllTrackedItems')->assertNotified('2 items added');
    expect($stocktake->lines()->count())->toBe(2);
});

it('adds negative stock as not counted without suggesting a count', function () {
    $stocktake = Stocktake::factory()->create();
    $variant = Variant::factory()->create(['sku' => 'NEGATIVE-UI']);
    StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $stocktake->location_id, 'qty' => -2]);

    Livewire::test(EditStocktake::class, ['record' => $stocktake->id])->callAction('addAllTrackedItems')
        ->assertNotified('1 item added');

    expect($stocktake->lines()->sole()->counted_qty)->toBeNull();
    expect(StockMovement::count())->toBe(0);
});

it('imports checked opening counts through the action modal', function () {
    Storage::fake('local');
    $stocktake = Stocktake::factory()->create();
    $variant = Variant::factory()->create(['sku' => 'OPEN-UI']);
    $file = UploadedFile::fake()->createWithContent('counts.csv', "sku,counted_qty\nOPEN-UI,19\n");

    Livewire::test(EditStocktake::class, ['record' => $stocktake->id])
        ->callAction('importCounts', data: ['file' => $file])->assertHasNoActionErrors()->assertNotified('1 count loaded');
    expect($stocktake->lines()->sole()->variant_id)->toBe($variant->id);
    expect($stocktake->lines()->sole()->counted_qty)->toBe(19);
    expect(Storage::disk('local')->allFiles('stocktake-imports'))->toBe([]);
});

it('does not expose stocktakes to sales staff', function () {
    $this->actingAs(User::factory()->sales()->withAppAuthentication()->create());
    expect(StocktakeResource::canViewAny())->toBeFalse();
    $this->get(StocktakeResource::getUrl())->assertForbidden();
});

it('shows invalid CSV errors without loading any counts', function () {
    Storage::fake('local');
    $stocktake = Stocktake::factory()->create();
    $variant = Variant::factory()->create(['sku' => 'VALID-UI']);
    $file = UploadedFile::fake()->createWithContent('counts.csv', "sku,counted_qty\nVALID-UI,19\nUNKNOWN-UI,2\n");

    Livewire::test(EditStocktake::class, ['record' => $stocktake->id])
        ->callAction('importCounts', data: ['file' => $file])->assertHasNoActionErrors()
        ->assertNotified('Counts could not be imported');

    expect($stocktake->lines()->count())->toBe(0);
    expect(StockLevel::query()->where('variant_id', $variant->id)->count())->toBe(0);
    expect(StockMovement::count())->toBe(0);
    expect(Storage::disk('local')->allFiles('stocktake-imports'))->toBe([]);
});

it('reports a commit-time import validation error and removes its upload', function () {
    Storage::fake('local');
    $stocktake = Stocktake::factory()->create();
    Variant::factory()->create(['sku' => 'FAIL-COMMIT']);
    StocktakeLine::creating(function (): void {
        throw ValidationException::withMessages(['file' => "This item doesn't track stock."]);
    });
    $file = UploadedFile::fake()->createWithContent('counts.csv', "sku,counted_qty\nFAIL-COMMIT,19\n");

    Livewire::test(EditStocktake::class, ['record' => $stocktake->id])->callAction('importCounts', data: ['file' => $file])
        ->assertNotified("This item doesn't track stock.");

    expect($stocktake->lines()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('stocktake-imports'))->toBe([]);
});

it('removes the uploaded counts file even when committing fails unexpectedly', function () {
    Storage::fake('local');
    $stocktake = Stocktake::factory()->create();
    Variant::factory()->create(['sku' => 'FAIL-UNEXPECTED']);
    StocktakeLine::creating(function (): void {
        throw new RuntimeException('Unexpected commit failure');
    });
    $file = UploadedFile::fake()->createWithContent('counts.csv', "sku,counted_qty\nFAIL-UNEXPECTED,19\n");

    expect(fn () => Livewire::test(EditStocktake::class, ['record' => $stocktake->id])->callAction('importCounts', data: ['file' => $file]))
        ->toThrow(RuntimeException::class, 'Unexpected commit failure');

    expect($stocktake->lines()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('stocktake-imports'))->toBe([]);
});

it('shows stale stocktake status errors on a second mounted edit page', function (string $action) {
    $stocktake = Stocktake::factory()->create();
    StocktakeLine::factory()->create(['stocktake_id' => $stocktake->id, 'counted_qty' => 4]);
    $first = Livewire::test(EditStocktake::class, ['record' => $stocktake->id]);
    $second = Livewire::test(EditStocktake::class, ['record' => $stocktake->id]);

    $first->callAction('post')->assertNotified('Stocktake posted');
    $second->callAction($action)->assertNotified('Only draft stocktakes can be changed.');

    expect($stocktake->fresh()->status)->toBe(StocktakeStatus::Posted);
    expect(StockMovement::count())->toBe(1);
})->with(['post', 'cancelStocktake']);

it('shows not-counted rows without a difference and lets staff enter a required real count', function () {
    $stocktake = Stocktake::factory()->create();
    $uncounted = StocktakeLine::factory()->create(['stocktake_id' => $stocktake->id, 'counted_qty' => null]);
    $counted = StocktakeLine::factory()->create(['stocktake_id' => $stocktake->id, 'counted_qty' => 0]);
    $page = Livewire::test(LinesRelationManager::class, ['ownerRecord' => $stocktake, 'pageClass' => EditStocktake::class]);

    $page->assertTableColumnStateSet('counted_qty', null, $uncounted)->assertTableColumnStateSet('difference', null, $uncounted)
        ->assertSee('Not counted')->filterTable('not_counted')->assertCanSeeTableRecords([$uncounted])->assertCanNotSeeTableRecords([$counted]);
    $page->callAction(TestAction::make('edit')->table($uncounted), data: ['counted_qty' => null])->assertHasActionErrors(['counted_qty' => 'required']);
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $stocktake, 'pageClass' => EditStocktake::class])
        ->callAction(TestAction::make('edit')->table($uncounted), data: ['counted_qty' => 0])->assertHasNoActionErrors();

    expect($uncounted->fresh()->counted_qty)->toBe(0);
});

it('previews changed and uncounted lines in the post confirmation', function () {
    $stocktake = Stocktake::factory()->create();
    StocktakeLine::factory()->create(['stocktake_id' => $stocktake->id, 'counted_qty' => null]);
    StocktakeLine::factory()->create(['stocktake_id' => $stocktake->id, 'counted_qty' => 5]);

    $page = Livewire::test(EditStocktake::class, ['record' => $stocktake->id])->mountAction('post');
    expect($page->instance()->getMountedAction()->getModalDescription())->toBe('1 line will change stock, 1 not counted (skipped)');
});
