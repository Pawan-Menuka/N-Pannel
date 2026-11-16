<?php

use App\Actions\Catalog\ExportProducts;
use App\Filament\Pages\ImportProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('local');
});

it('lets stock check and import an uploaded CSV through Livewire', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    Category::factory()->create(['slug' => 'ceilings']);
    $path = Storage::disk('local')->path('template.csv');
    app(ExportProducts::class)->template($path);
    $upload = UploadedFile::fake()->createWithContent('products.csv', file_get_contents($path));

    $page = Livewire::test(ImportProducts::class)->fillForm(['file' => $upload])->call('checkFile')
        ->assertHasNoFormErrors()->assertSet('report.errors', [])->assertSet('report.rowsRead', 2);
    expect($page->get('checkedFingerprint'))->toBe(hash_file('sha256', $page->get('checkedPath')));
    $storedPath = $page->get('checkedPath');
    expect(is_file($storedPath))->toBeTrue();
    expect(Product::count())->toBe(0);
    $page->call('import')->assertHasNoFormErrors()->assertSet('report.committed', true)->assertSet('checkedFingerprint', null);
    expect(is_file($storedPath))->toBeFalse();

    expect(Product::count())->toBe(1);
    expect(Variant::count())->toBe(2);
    expect(StockLevel::count())->toBe(0);
    expect(StockMovement::count())->toBe(0);
});

it('requires a clean check before importing', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $path = Storage::disk('local')->path('template.csv');
    app(ExportProducts::class)->template($path);

    Livewire::test(ImportProducts::class)->fillForm(['file' => UploadedFile::fake()->createWithContent('products.csv', file_get_contents($path))])
        ->call('import')->assertHasFormErrors(['file']);

    expect(Product::count())->toBe(0);
});

it('invalidates a check when the stored content changes', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    Category::factory()->create(['slug' => 'ceilings']);
    $path = Storage::disk('local')->path('template.csv');
    app(ExportProducts::class)->template($path);
    $page = Livewire::test(ImportProducts::class)->fillForm(['file' => UploadedFile::fake()->createWithContent('products.csv', file_get_contents($path))])
        ->call('checkFile')->assertSet('report.errors', []);
    file_put_contents($page->get('checkedPath'), "\n", FILE_APPEND);

    $page->call('import')->assertHasFormErrors(['file']);

    expect(Product::count())->toBe(0);
});

it('requires another check when the uploaded file is replaced', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    Category::factory()->create(['slug' => 'ceilings']);
    $path = Storage::disk('local')->path('template.csv');
    app(ExportProducts::class)->template($path);
    $contents = file_get_contents($path);
    $page = Livewire::test(ImportProducts::class)
        ->fillForm(['file' => UploadedFile::fake()->createWithContent('products.csv', $contents)])
        ->call('checkFile')->assertSet('report.errors', []);

    $page->fillForm(['file' => UploadedFile::fake()->createWithContent('replacement.csv', str_replace('1,250.50', '1,350.50', $contents))])
        ->assertSet('checkedFingerprint', null)->call('import')->assertHasFormErrors(['file']);

    expect(Product::count())->toBe(0);
    expect(Variant::count())->toBe(0);
});

it('rejects uploads larger than two MB', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());

    Livewire::test(ImportProducts::class)->fillForm(['file' => UploadedFile::fake()->create('large.csv', 2049, 'text/csv')])
        ->call('checkFile')->assertHasFormErrors(['file']);

    expect(Product::count())->toBe(0);
});

it('forbids sales from the import page', function () {
    $this->actingAs(User::factory()->sales()->withAppAuthentication()->create());

    $this->get(ImportProducts::getUrl())->assertForbidden();
    Livewire::test(ImportProducts::class)->assertForbidden();
});
