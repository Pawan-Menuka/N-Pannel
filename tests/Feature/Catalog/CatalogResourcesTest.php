<?php

use App\Actions\Catalog\DeleteCategory;
use App\Actions\Catalog\DeleteProduct;
use App\Actions\Catalog\DeleteVariant;
use App\Enums\UserRole;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\Category;
use App\Models\GoodsReceiptLine;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Models\Variant;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('lets catalog staff create and edit categories products and variants', function (UserRole $role) {
    $this->actingAs(User::factory()->withAppAuthentication()->create(['role' => $role]));

    Livewire::test(CreateCategory::class)->fillForm(['name.en' => 'Ceilings', 'slug' => 'ceilings'])->call('create')->assertHasNoFormErrors();
    $category = Category::sole();
    Livewire::test(EditCategory::class, ['record' => $category->id])->fillForm(['name.en' => 'Panels'])->call('save')->assertHasNoFormErrors();
    expect($category->fresh()->getTranslation('name', 'en'))->toBe('Panels');

    Livewire::test(CreateProduct::class)->fillForm(['category_id' => $category->id, 'title.en' => 'Panel', 'slug' => 'panel'])
        ->call('create')->assertHasNoFormErrors();
    $product = Product::sole();
    Livewire::test(EditProduct::class, ['record' => $product->id])->fillForm(['brand' => 'N-Pannel'])->call('save')->assertHasNoFormErrors();
    expect($product->fresh()->brand)->toBe('N-Pannel');

    Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
        ->callAction(TestAction::make('create')->table(), data: [
            'sku' => ' panel-001 ', 'sale_unit' => 'panel', 'price_cents' => '1,250.50', 'trade_price_cents' => '1,100',
            'length_mm' => 2440, 'width_mm' => 1220, 'thickness_micron' => '7.5',
        ])->assertHasNoFormErrors();
    $variant = Variant::sole();
    expect($variant->sku)->toBe('PANEL-001');
    expect($variant->price_cents)->toBe(125050);
    expect($variant->trade_price_cents)->toBe(110000);
    expect($variant->thickness_micron)->toBe(7500);
    Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
        ->callAction(TestAction::make('edit')->table($variant), data: ['price_cents' => '1,300', 'label' => 'Updated'])
        ->assertHasNoFormErrors();
    expect($variant->fresh()->price_cents)->toBe(130000);
    expect($variant->fresh()->label)->toBe('Updated');
})->with([UserRole::Admin, UserRole::Stock]);

it('lets sales view products and variants but hides all mutation actions', function () {
    $this->actingAs(User::factory()->sales()->withAppAuthentication()->create());
    $variant = Variant::factory()->create();
    $product = $variant->product;

    $this->get(ProductResource::getUrl())->assertOk();
    $this->get(ProductResource::getUrl('view', ['record' => $product]))->assertOk();
    $this->get(ProductResource::getUrl('create'))->assertForbidden();
    $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertForbidden();
    $this->get(CategoryResource::getUrl('create'))->assertForbidden();
    $this->get(CategoryResource::getUrl('edit', ['record' => $product->category]))->assertForbidden();
    Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$product])->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('edit')->table($product))->assertActionHidden(TestAction::make('delete')->table($product));
    Livewire::test(ViewProduct::class, ['record' => $product->id])->assertActionHidden('edit');
    Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => ViewProduct::class])
        ->assertCanSeeTableRecords([$variant])->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($variant))->assertActionHidden(TestAction::make('delete')->table($variant));
});

it('forbids inactive users from the catalog', function () {
    $this->actingAs(User::factory()->stock()->inactive()->withAppAuthentication()->create());

    $this->get(ProductResource::getUrl())->assertForbidden();
    $this->get(CategoryResource::getUrl())->assertForbidden();
});

it('edits Sinhala without replacing English or Tamil translations', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $product = Product::factory()->create(['title' => ['en' => 'English title', 'si' => 'පැරණි', 'ta' => 'தமிழ்']]);

    Livewire::test(EditProduct::class, ['record' => $product->id])->assertFormSet(['title.en' => 'English title', 'title.ta' => 'தமிழ்'])
        ->fillForm(['title.si' => 'අලුත්'])->call('save')->assertHasNoFormErrors();

    expect($product->fresh()->getTranslations('title'))->toBe(['en' => 'English title', 'si' => 'අලුත්', 'ta' => 'தமிழ்']);
});

it('shows the money validation message for invalid rupees and rejects trade above retail', function (array $data, string $field, string $message) {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $product = Product::factory()->create();

    Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
        ->callAction(TestAction::make('create')->table(), data: array_replace([
            'sku' => 'PANEL-NEW', 'sale_unit' => 'panel', 'price_cents' => '1,250.50',
        ], $data))->assertHasActionErrors([$field => $message]);

    expect(Variant::count())->toBe(0);
})->with([
    [['price_cents' => '12,50'], 'price_cents', 'Enter an amount like 1,250.00'],
    [['trade_price_cents' => '1,251'], 'trade_price_cents', 'Trade price must not exceed price'],
    [['price_cents' => '0'], 'price_cents', 'Price must be greater than zero'],
]);

it('rejects a duplicate SKU after trimming and uppercasing', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $variant = Variant::factory()->create(['sku' => 'PANEL-1']);

    Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $variant->product, 'pageClass' => EditProduct::class])
        ->callAction(TestAction::make('create')->table(), data: ['sku' => ' panel-1 ', 'sale_unit' => 'panel', 'price_cents' => '1250'])
        ->assertHasActionErrors(['sku' => 'This SKU has already been taken']);

    expect(Variant::count())->toBe(1);
});

it('shows feet inches and square feet for a sheet', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $variant = Variant::factory()->create(['length_mm' => 2440, 'width_mm' => 1220, 'pieces_per_unit' => 1]);

    $page = Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $variant->product, 'pageClass' => EditProduct::class])
        ->mountAction(TestAction::make('edit')->table($variant));

    $schema = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName());
    expect($schema)->toBeInstanceOf(Schema::class);
    foreach (['area' => '32.04', 'length_feet' => '8\' 0"', 'width_feet' => '4\' 0"'] as $field => $expected) {
        $component = $schema->getComponentByStatePath($field);
        expect($component)->toBeInstanceOf(TextEntry::class);
        expect($component->getState())->toBe($expected);
    }
});

it('refuses category deletion with products or children and displays the guard notification', function () {
    $actor = User::factory()->stock()->withAppAuthentication()->create();
    $this->actingAs($actor);
    $product = Product::factory()->create();
    $category = $product->category;

    expect(fn () => app(DeleteCategory::class)->handle($category, $actor))->toThrow(ValidationException::class, 'Move or remove its products first');
    Livewire::test(EditCategory::class, ['record' => $category->id])->callAction('delete')
        ->assertNotified('Move or remove its products first');
    $this->assertModelExists($category);

    $parent = Category::factory()->create();
    Category::factory()->create(['parent_id' => $parent->id]);
    expect(fn () => app(DeleteCategory::class)->handle($parent, $actor))->toThrow(ValidationException::class);
    $this->assertModelExists($parent);
});

it('refuses product and variant deletion for every history type', function (string $history) {
    $actor = User::factory()->stock()->create();
    $variant = Variant::factory()->create();
    match ($history) {
        'stock' => StockMovement::factory()->create(['variant_id' => $variant->id]),
        'order' => OrderLine::factory()->create(['variant_id' => $variant->id]),
        'receipt' => GoodsReceiptLine::factory()->create(['variant_id' => $variant->id]),
        'stocktake' => StocktakeLine::factory()->create(['variant_id' => $variant->id]),
    };

    expect(fn () => app(DeleteProduct::class)->handle($variant->product, $actor))->toThrow(ValidationException::class, 'Deactivate it instead');
    expect(fn () => app(DeleteVariant::class)->handle($variant, $actor))->toThrow(ValidationException::class, 'Deactivate it instead');
    $this->assertModelExists($variant);
    $this->assertModelExists($variant->product);
})->with(['stock', 'order', 'receipt', 'stocktake']);

it('deletes products with no history and their variants', function () {
    $actor = User::factory()->stock()->create();
    $variant = Variant::factory()->create();
    $product = $variant->product;

    expect(app(DeleteProduct::class)->handle($product, $actor))->toBeTrue();

    $this->assertModelMissing($product);
    $this->assertModelMissing($variant);
});

it('redirects after deleting a product from its edit page', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->id])->callAction('delete')
        ->assertRedirect(ProductResource::getUrl());

    $this->assertModelMissing($product);
});

it('searches products by variant SKU and filters by category and activity', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $variant = Variant::factory()->create(['sku' => 'FIND-ME']);
    $other = Product::factory()->create(['is_active' => false]);

    Livewire::test(ListProducts::class)->searchTable('FIND-ME')->assertCanSeeTableRecords([$variant->product])->assertCanNotSeeTableRecords([$other]);
    Livewire::test(ListProducts::class)->filterTable('category_id', $variant->product->category_id)
        ->assertCanSeeTableRecords([$variant->product])->assertCanNotSeeTableRecords([$other]);
    Livewire::test(ListProducts::class)->filterTable('is_active', false)->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$variant->product]);
});

it('refuses case-insensitive SKU duplicates in the form and the database', function () {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    $variant = Variant::factory()->create(['sku' => 'abc-1']);

    Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $variant->product, 'pageClass' => EditProduct::class])
        ->callAction(TestAction::make('create')->table(), data: ['sku' => 'ABC-1', 'sale_unit' => 'panel', 'price_cents' => '1250'])
        ->assertHasActionErrors(['sku' => 'This SKU has already been taken']);
    expect(Variant::count())->toBe(1);
    expect(fn () => DB::transaction(fn () => Variant::factory()->create(['product_id' => $variant->product_id, 'sku' => 'ABC-1'])))
        ->toThrow(QueryException::class);
    expect(Variant::count())->toBe(1);
});

it('validates the slug format on category and product forms', function (string $slug) {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    $category = Category::factory()->create();

    Livewire::test(CreateCategory::class)->fillForm(['name.en' => 'New category', 'slug' => $slug])
        ->call('create')->assertHasFormErrors(['slug' => 'regex']);
    Livewire::test(CreateProduct::class)->fillForm(['category_id' => $category->id, 'title.en' => 'New product', 'slug' => $slug])
        ->call('create')->assertHasFormErrors(['slug' => 'regex']);

    expect(Category::count())->toBe(1);
    expect(Product::count())->toBe(0);
})->with(['Upper', 'two--hyphens', 'has_space', '-leading', 'trailing-']);

it('validates normalized SKU format and maximum length in the form', function (string $sku, string $rule) {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    $product = Product::factory()->create();

    Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
        ->callAction(TestAction::make('create')->table(), data: ['sku' => $sku, 'sale_unit' => 'panel', 'price_cents' => '1250'])
        ->assertHasActionErrors(['sku' => $rule]);

    expect(Variant::count())->toBe(0);
})->with([['-ABC', 'regex'], ['ABC 1', 'regex'], ['ABC:1', 'regex'], [str_repeat('A', 65), 'max']]);

it('logs the deletion of each variant when a product is deleted', function () {
    $actor = User::factory()->stock()->create();
    $product = Product::factory()->create();
    $variants = Variant::factory()->count(3)->create(['product_id' => $product->id]);

    app(DeleteProduct::class)->handle($product, $actor);

    $this->assertModelMissing($product);
    foreach ($variants as $variant) {
        $this->assertModelMissing($variant);
        expect(Activity::forSubject($variant)->where('event', 'deleted')->count())->toBe(1);
    }
});

it('excludes all descendants from the category parent picker and rejects forged cyclic saves', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $root = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $root->id]);
    $grandchild = Category::factory()->create(['parent_id' => $child->id]);
    $unrelated = Category::factory()->create();

    $page = Livewire::test(EditCategory::class, ['record' => $root->id])
        ->assertFormFieldExists('parent_id', function (Select $field) use ($root, $child, $grandchild, $unrelated): bool {
            $options = $field->getOptions();

            return ! array_key_exists($root->id, $options) && ! array_key_exists($child->id, $options)
                && ! array_key_exists($grandchild->id, $options) && array_key_exists($unrelated->id, $options);
        });
    $page->fillForm(['parent_id' => $grandchild->id])->call('save')->assertHasFormErrors(['parent_id']);
    expect($root->fresh()->parent_id)->toBeNull();
    expect(fn () => $root->update(['parent_id' => $grandchild->id]))->toThrow(ValidationException::class,
        'A category cannot have itself or a descendant as its parent');
    expect($root->fresh()->parent_id)->toBeNull();
    expect($child->fresh()->parent_id)->toBe($root->id);
});
