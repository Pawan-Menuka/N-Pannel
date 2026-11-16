<?php

use App\Actions\Catalog\ExportProducts;
use App\Actions\Catalog\ImportProducts;
use App\Domain\Catalog\ImportRow;
use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
});

/** @return array<string, string> */
function catalogImportRow(array $overrides = []): array
{
    return array_replace(array_fill_keys(ImportRow::Columns, ''), [
        'category_slug' => 'ceilings', 'product_slug' => 'ceiling-panel', 'title_en' => 'Ceiling panel',
        'title_si' => 'සිවිලිම', 'title_ta' => 'கூரை', 'sku' => 'PANEL-1', 'sale_unit' => 'panel',
        'price_lkr' => '1,250.50', 'length_mm' => '2440', 'width_mm' => '1220', 'weight_grams' => '1800',
        'thickness_mm' => '7.5',
    ], $overrides);
}

/** @param iterable<array<string, string>> $rows
 * @param  list<string>|null  $headers
 */
function catalogCsv(iterable $rows, ?array $headers = null, bool $bom = false): string
{
    $path = Storage::disk('local')->path('input-'.bin2hex(random_bytes(6)).'.csv');
    $handle = fopen($path, 'wb');
    if ($bom) {
        fwrite($handle, "\xEF\xBB\xBF");
    }
    $headers ??= ImportRow::Columns;
    fputcsv($handle, $headers, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, array_map(fn (string $column): string => $row[$column] ?? '', $headers), ',', '"', '');
    }
    fclose($handle);

    return $path;
}

it('checks and imports the downloadable template without touching stock', function () {
    Category::factory()->create(['slug' => 'ceilings']);
    $actor = User::factory()->stock()->create();
    $path = Storage::disk('local')->path('template.csv');
    app(ExportProducts::class)->template($path);

    $dry = app(ImportProducts::class)->run($path, false, $actor);

    expect($dry->errors)->toBe([]);
    expect($dry->rowsRead)->toBe(2);
    expect($dry->productsToCreate)->toBe(1);
    expect($dry->variantsToCreate)->toBe(2);
    expect(Product::count())->toBe(0);
    $result = app(ImportProducts::class)->run($path, true, $actor);
    expect($result->committed)->toBeTrue();
    expect(Product::count())->toBe(1);
    expect(Variant::count())->toBe(2);
    expect(StockLevel::count())->toBe(0);
    expect(StockMovement::count())->toBe(0);

    $repeat = app(ImportProducts::class)->run($path, true, $actor);
    expect($repeat->productsToCreate)->toBe(0);
    expect($repeat->variantsToCreate)->toBe(0);
    expect($repeat->productsToUpdate)->toBe(0);
    expect($repeat->variantsToUpdate)->toBe(0);
    expect($repeat->productsUnchanged)->toBe(1);
    expect($repeat->variantsUnchanged)->toBe(2);
});

it('reports each invalid row value and refuses all writes', function (string $column, string $value) {
    Category::factory()->create(['slug' => 'ceilings']);
    $actor = User::factory()->stock()->create();
    $path = catalogCsv([catalogImportRow(), catalogImportRow(['sku' => 'PANEL-2', $column => $value])]);

    $report = app(ImportProducts::class)->run($path, true, $actor);

    expect(collect($report->errors)->contains(fn (array $error): bool => $error['row'] === 3 && $error['column'] === $column))->toBeTrue();
    expect($report->committed)->toBeFalse();
    expect(Product::count())->toBe(0);
    expect(Variant::count())->toBe(0);
    expect(StockLevel::count())->toBe(0);
    expect(StockMovement::count())->toBe(0);
})->with([
    'required category' => ['category_slug', ''], 'required product' => ['product_slug', ''],
    'required title' => ['title_en', ''], 'required SKU' => ['sku', ''],
    'required unit' => ['sale_unit', ''], 'required price' => ['price_lkr', ''],
    'unknown category' => ['category_slug', 'missing'], 'unknown unit' => ['sale_unit', 'carton'],
    'unknown status' => ['stock_status', 'unknown'], 'invalid money' => ['price_lkr', '12,50'],
    'zero price' => ['price_lkr', '0'], 'negative price' => ['price_lkr', '-1'],
    'invalid trade price' => ['trade_price_lkr', 'a'], 'trade above retail' => ['trade_price_lkr', '1251'],
    'invalid thickness' => ['thickness_mm', '0.0001'],
    'fractional length' => ['length_mm', '2.5'], 'negative length' => ['length_mm', '-1'],
    'fractional width' => ['width_mm', '2.5'], 'negative width' => ['width_mm', '-1'],
    'fractional weight' => ['weight_grams', '2.5'], 'negative weight' => ['weight_grams', '-1'],
    'fractional reorder level' => ['reorder_level', '2.5'], 'negative reorder level' => ['reorder_level', '-1'],
    'zero pieces' => ['pieces_per_unit', '0'], 'negative pieces' => ['pieces_per_unit', '-1'],
    'fractional pieces' => ['pieces_per_unit', '1.5'], 'invalid boolean' => ['track_stock', 'maybe'],
    'normalized SKU too long' => ['sku', str_repeat('ß', 255)],
    'zero is not a stock status' => ['stock_status', '0'],
]);

it('refuses unknown headers with their column name', function () {
    $actor = User::factory()->stock()->create();
    $path = catalogCsv([catalogImportRow()], [...ImportRow::Columns, 'opening_stock']);

    $report = app(ImportProducts::class)->run($path, true, $actor);

    expect($report->errors)->toContain(['row' => 1, 'column' => 'opening_stock', 'message' => 'Unknown header']);
    expect(Product::count())->toBe(0);
});

it('requires a header and all required column names', function () {
    $actor = User::factory()->stock()->create();
    $path = catalogCsv([], ['sku']);

    $report = app(ImportProducts::class)->run($path, true, $actor);

    expect($report->errors)->toContain(['row' => 1, 'column' => 'price_lkr', 'message' => 'Required header is missing']);
    Storage::disk('local')->put('empty.csv', '');
    $empty = app(ImportProducts::class)->run(Storage::disk('local')->path('empty.csv'), true, $actor);
    expect($empty->errors)->toContain(['row' => 1, 'column' => 'header', 'message' => 'A header row is required']);
});

it('refuses duplicate normalized SKUs in the file', function () {
    Category::factory()->create(['slug' => 'ceilings']);
    $path = catalogCsv([catalogImportRow(), catalogImportRow(['sku' => ' panel-1 '])]);

    $report = app(ImportProducts::class)->run($path, true, User::factory()->stock()->create());

    expect($report->errors)->toContain(['row' => 3, 'column' => 'sku', 'message' => 'Duplicate SKU within this file']);
    expect(Variant::count())->toBe(0);
});

it('refuses moving an existing SKU to another product', function (string $sku) {
    Category::factory()->create(['slug' => 'ceilings']);
    $variant = Variant::factory()->create(['sku' => $sku]);

    $report = app(ImportProducts::class)->run(catalogCsv([catalogImportRow()]), true, User::factory()->stock()->create());

    expect($report->errors)->toContain(['row' => 2, 'column' => 'sku', 'message' => 'SKU already belongs to a different product']);
    expect($variant->fresh()->product_id)->toBe($variant->product_id);
})->with(['PANEL-1', 'panel-1']);

it('refuses conflicting product values on later rows', function (string $column, string $value) {
    Category::factory()->create(['slug' => 'ceilings']);
    Category::factory()->create(['slug' => 'other']);
    $path = catalogCsv([catalogImportRow(), catalogImportRow(['sku' => 'PANEL-2', $column => $value])]);

    $report = app(ImportProducts::class)->run($path, true, User::factory()->stock()->create());

    expect($report->errors)->toContain(['row' => 3, 'column' => $column, 'message' => 'Product values disagree with another row for this product_slug']);
    expect(Product::count())->toBe(0);
})->with([
    ['category_slug', 'other'], ['title_en', 'Different'], ['title_si', 'Different'], ['title_ta', 'Different'],
    ['summary_en', 'Different'], ['brand', 'Different'], ['product_active', 'no'],
]);

it('supports BOM and reordered headers and warns about missing translations and weight', function () {
    Category::factory()->create(['slug' => 'ceilings']);
    $path = catalogCsv([catalogImportRow(['title_si' => '', 'title_ta' => '', 'weight_grams' => ''])], array_reverse(ImportRow::Columns), true);

    $report = app(ImportProducts::class)->run($path, true, User::factory()->stock()->create());

    expect($report->errors)->toBe([]);
    expect($report->warnings)->toHaveCount(3);
    expect($report->warnings[0]['row'])->toBe(2);
    expect($report->committed)->toBeTrue();
    expect(Variant::sole()->price_cents)->toBe(125050);
});

it('round trips the current catalog with no changed updates or writes', function () {
    $variant = Variant::factory()->create(['sku' => 'PVC-1234ab']);
    $variant->product->setTranslations('summary', ['en' => 'Summary', 'si' => 'සාරාංශය'])->save();
    $actor = User::factory()->stock()->create();
    $path = Storage::disk('local')->path('catalog.csv');
    app(ExportProducts::class)->run($path);
    $updatedAt = $variant->updated_at;

    $report = app(ImportProducts::class)->run($path, true, $actor);

    expect($report->errors)->toBe([]);
    expect($report->productsToCreate)->toBe(0);
    expect($report->variantsToCreate)->toBe(0);
    expect($report->productsToUpdate)->toBe(0);
    expect($report->variantsToUpdate)->toBe(0);
    expect($report->productsUnchanged)->toBe(1);
    expect($report->variantsUnchanged)->toBe(1);
    expect(Variant::count())->toBe(1);
    expect($variant->fresh()->sku)->toBe('PVC-1234ab');
    expect($variant->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    expect($variant->product->fresh()->getTranslation('summary', 'si'))->toBe('සාරාංශය');
});

it('reports changed updates while preserving fields outside the CSV', function () {
    Category::factory()->create(['slug' => 'ceilings']);
    $actor = User::factory()->stock()->create();
    app(ImportProducts::class)->run(catalogCsv([catalogImportRow()]), true, $actor);
    $product = Product::sole();
    $product->setTranslation('description', 'en', 'Keep this')->save();

    $report = app(ImportProducts::class)->run(catalogCsv([catalogImportRow(['title_en' => 'New title', 'price_lkr' => '1300'])]), true, $actor);

    expect($report->productsToUpdate)->toBe(1);
    expect($report->variantsToUpdate)->toBe(1);
    expect($product->fresh()->getTranslation('description', 'en'))->toBe('Keep this');
    expect(Variant::sole()->price_cents)->toBe(130000);
});

it('refuses files with more than 5000 variant rows', function () {
    Category::factory()->create(['slug' => 'ceilings']);
    $rows = (function (): Generator {
        for ($index = 1; $index <= 5002; $index++) {
            yield catalogImportRow(['sku' => 'PANEL-'.$index]);
        }
    })();

    $report = app(ImportProducts::class)->run(catalogCsv($rows), true, User::factory()->stock()->create());

    expect($report->errors)->toContain(['row' => 5002, 'column' => 'file', 'message' => 'A file may contain at most 5,000 rows']);
    expect($report->errors)->toHaveCount(1);
    expect($report->rowsRead)->toBe(5001);
    expect($report->productsToCreate)->toBe(0);
    expect($report->variantsToCreate)->toBe(0);
    expect($report->committed)->toBeFalse();
    expect(Product::count())->toBe(0);
    expect(Variant::count())->toBe(0);
});

it('accepts the documented boolean spellings', function (string $value, bool $expected) {
    Category::factory()->create(['slug' => 'ceilings']);
    $path = catalogCsv([catalogImportRow(['product_active' => $value, 'variant_active' => $value, 'track_stock' => $value])]);

    $report = app(ImportProducts::class)->run($path, true, User::factory()->stock()->create());

    expect($report->errors)->toBe([]);
    expect(Product::sole()->is_active)->toBe($expected);
    expect(Variant::sole()->is_active)->toBe($expected);
    expect(Variant::sole()->track_stock)->toBe($expected);
})->with([['yes', true], ['true', true], ['1', true], ['', true], ['no', false], ['false', false], ['0', false]]);

it('preserves every omitted optional column during a price-only update', function () {
    $category = Category::factory()->create(['slug' => 'ceilings']);
    $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => false]);
    $variant = Variant::factory()->create([
        'product_id' => $product->id, 'sku' => 'PARTIAL-1', 'track_stock' => false,
        'is_active' => false, 'stock_status' => StockStatus::MadeToOrder,
    ]);
    $productBefore = $product->fresh()->getAttributes();
    $variantBefore = $variant->fresh()->getAttributes();
    $path = catalogCsv([catalogImportRow([
        'product_slug' => $product->slug, 'title_en' => $product->getTranslation('title', 'en'),
        'sku' => $variant->sku, 'sale_unit' => $variant->sale_unit->value, 'price_lkr' => '1500',
    ])], ['category_slug', 'product_slug', 'title_en', 'sku', 'sale_unit', 'price_lkr']);

    $report = app(ImportProducts::class)->run($path, true, User::factory()->stock()->create());

    expect($report->errors)->toBe([]);
    expect($report->committed)->toBeTrue();
    expect($report->productsUnchanged)->toBe(1);
    expect($report->variantsToUpdate)->toBe(1);
    expect($product->fresh()->getAttributes())->toBe($productBefore);
    expect(Arr::except($variant->fresh()->getAttributes(), ['price_cents', 'updated_at']))
        ->toBe(Arr::except($variantBefore, ['price_cents', 'updated_at']));
    expect($variant->fresh()->price_cents)->toBe(150000);
});

it('uses normal model defaults for new records with omitted optional columns', function () {
    Category::factory()->create(['slug' => 'ceilings']);
    $path = catalogCsv([catalogImportRow()], ['category_slug', 'product_slug', 'title_en', 'sku', 'sale_unit', 'price_lkr']);

    $report = app(ImportProducts::class)->run($path, true, User::factory()->stock()->create());

    expect($report->errors)->toBe([]);
    $product = Product::sole();
    $variant = Variant::sole();
    expect($product->is_active)->toBeTrue();
    expect($product->brand)->toBeNull();
    expect($product->getTranslations('title'))->toBe(['en' => 'Ceiling panel']);
    expect($product->getTranslations('summary'))->toBe([]);
    expect($variant->track_stock)->toBeTrue();
    expect($variant->is_active)->toBeTrue();
    expect($variant->pieces_per_unit)->toBe(1);
    expect($variant->stock_status)->toBe(StockStatus::InStock);
    expect($variant->trade_price_cents)->toBeNull();
    expect($variant->length_mm)->toBeNull();
});

it('escapes spreadsheet formulas and restores exactly one export prefix on import', function (string $value) {
    $variant = Variant::factory()->create(['sku' => 'SAFE-1', 'label' => $value]);
    $variant->product->setTranslation('title', 'en', $value)->save();
    $path = Storage::disk('local')->path('formulas.csv');
    app(ExportProducts::class)->run($path);
    $handle = fopen($path, 'rb');
    expect(fread($handle, 3))->toBe("\xEF\xBB\xBF");
    $header = fgetcsv($handle, 0, ',', '"', '');
    $exported = array_combine($header, fgetcsv($handle, 0, ',', '"', ''));
    fclose($handle);
    expect($exported['title_en'])->toBe("'".$value);
    expect($exported['label'])->toBe("'".$value);
    $actor = User::factory()->stock()->create();
    $activityCount = Activity::count();

    $report = app(ImportProducts::class)->run($path, true, $actor);

    expect($report->errors)->toBe([]);
    expect($report->productsToUpdate)->toBe(0);
    expect($report->variantsToUpdate)->toBe(0);
    expect($report->productsUnchanged)->toBe(1);
    expect($report->variantsUnchanged)->toBe(1);
    expect($variant->fresh()->label)->toBe($value);
    expect($variant->product->fresh()->getTranslation('title', 'en'))->toBe($value);
    expect(Activity::count())->toBe($activityCount);
})->with(['=HYPERLINK("http://x","y")', '+SUM(A1)', '-SUM(A1)', '@SUM(A1)', "\tFormula", "\rFormula", "'=literal title", "''=literal title"]);

it('exports a BOM for both catalog downloads and templates', function () {
    $export = Storage::disk('local')->path('export.csv');
    $template = Storage::disk('local')->path('template.csv');

    app(ExportProducts::class)->run($export);
    app(ExportProducts::class)->template($template);

    foreach ([$export, $template] as $path) {
        $handle = fopen($path, 'rb');
        expect(fread($handle, 3))->toBe("\xEF\xBB\xBF");
        expect(fgetcsv($handle, 0, ',', '"', ''))->toBe(ImportRow::Columns);
        fclose($handle);
    }
});

it('warns when translated titles look damaged', function (string $column, string $value) {
    Category::factory()->create(['slug' => 'ceilings']);

    $report = app(ImportProducts::class)->run(catalogCsv([catalogImportRow([$column => $value])]), false, User::factory()->stock()->create());

    expect($report->errors)->toBe([]);
    expect(collect($report->warnings)->contains(fn (array $warning): bool => $warning['row'] === 2
        && str_contains($warning['message'], 'looks like the file lost Sinhala/Tamil characters — was it re-saved as plain CSV?')))->toBeTrue();
})->with([['title_si', '??'], ['title_ta', "damaged\u{FFFD}"]]);

it('parses a BOM before a quoted header', function () {
    Category::factory()->create(['slug' => 'ceilings']);
    $path = Storage::disk('local')->path('quoted-header.csv');
    $handle = fopen($path, 'wb');
    fwrite($handle, "\xEF\xBB\xBF".'"category_slug","product_slug","title_en","sku","sale_unit","price_lkr"'."\r\n");
    fputcsv($handle, ['ceilings', 'quoted-panel', 'Panel', 'QUOTED-1', 'panel', '1250'], ',', '"', '');
    fclose($handle);

    $report = app(ImportProducts::class)->run($path, true, User::factory()->stock()->create());

    expect($report->errors)->toBe([]);
    expect($report->rowsRead)->toBe(1);
    expect(Variant::sole()->sku)->toBe('QUOTED-1');
});

it('ignores all-blank CSV rows including thousands of deleted Excel rows', function () {
    Category::factory()->create(['slug' => 'ceilings']);
    $rows = (function (): Generator {
        for ($index = 0; $index < 5001; $index++) {
            yield array_fill_keys(ImportRow::Columns, ' ');
        }
        yield catalogImportRow();
    })();

    $report = app(ImportProducts::class)->run(catalogCsv($rows), true, User::factory()->stock()->create());

    expect($report->errors)->toBe([]);
    expect($report->rowsRead)->toBe(1);
    expect(Variant::count())->toBe(1);
});

it('reports physical line numbers after a quoted multiline field', function () {
    Category::factory()->create(['slug' => 'ceilings']);
    $path = catalogCsv([
        catalogImportRow(['label' => "Line one\nLine two"]),
        catalogImportRow(['sku' => 'PANEL-2', 'price_lkr' => '-1']),
    ]);

    $report = app(ImportProducts::class)->run($path, true, User::factory()->stock()->create());

    expect($report->rowsRead)->toBe(2);
    expect(collect($report->errors)->contains(fn (array $error): bool => $error['row'] === 4 && $error['column'] === 'price_lkr'))->toBeTrue();
    expect(Product::count())->toBe(0);
});

it('preserves zero as optional text', function () {
    Category::factory()->create(['slug' => 'ceilings']);

    $report = app(ImportProducts::class)->run(catalogCsv([catalogImportRow(['brand' => '0', 'label' => '0', 'colour' => '0'])]), true, User::factory()->stock()->create());

    expect($report->errors)->toBe([]);
    expect(Product::sole()->brand)->toBe('0');
    expect(Variant::sole()->label)->toBe('0');
    expect(Variant::sole()->colour)->toBe('0');
});

it('limits translated titles and short text to 255 characters', function (string $column) {
    Category::factory()->create(['slug' => 'ceilings']);
    $actor = User::factory()->stock()->create();

    $invalid = app(ImportProducts::class)->run(catalogCsv([catalogImportRow([$column => str_repeat('a', 256)])]), true, $actor);

    expect($invalid->errors)->toContain(['row' => 2, 'column' => $column, 'message' => 'Must not exceed 255 characters']);
    expect(Product::count())->toBe(0);
    $valid = app(ImportProducts::class)->run(catalogCsv([catalogImportRow([$column => str_repeat('a', 255)])]), true, $actor);
    expect($valid->errors)->toBe([]);
    expect($valid->committed)->toBeTrue();
})->with(['title_en', 'title_si', 'title_ta', 'brand', 'label', 'colour']);

it('validates slug and SKU formats during import', function (string $column, string $value) {
    Category::factory()->create(['slug' => 'ceilings']);

    $report = app(ImportProducts::class)->run(catalogCsv([catalogImportRow([$column => $value])]), true, User::factory()->stock()->create());

    expect(collect($report->errors)->contains(fn (array $error): bool => $error['row'] === 2 && $error['column'] === $column))->toBeTrue();
    expect($report->committed)->toBeFalse();
    expect(Product::count())->toBe(0);
})->with([
    ['category_slug', 'Bad--Slug'], ['product_slug', 'Upper'], ['product_slug', 'bad_slug'], ['product_slug', '-bad'], ['product_slug', 'bad-'],
    ['sku', '-ABC'], ['sku', 'ABC 1'], ['sku', 'ABC:1'], ['sku', str_repeat('A', 65)],
]);

it('normalizes and accepts every permitted SKU separator', function () {
    Category::factory()->create(['slug' => 'ceilings']);

    $report = app(ImportProducts::class)->run(catalogCsv([catalogImportRow(['sku' => ' ab/c_1.2-3 '])]), true, User::factory()->stock()->create());

    expect($report->errors)->toBe([]);
    expect(Variant::sole()->sku)->toBe('AB/C_1.2-3');
});

it('reports invalid UTF-8 without trying to persist broken translation JSON', function () {
    Category::factory()->create(['slug' => 'ceilings']);
    $path = catalogCsv([catalogImportRow(['title_si' => "\xFF"])]);

    $report = app(ImportProducts::class)->run($path, true, User::factory()->stock()->create());

    expect($report->errors)->toContain(['row' => 2, 'column' => 'title_si', 'message' => 'Text must be UTF-8']);
    expect($report->committed)->toBeFalse();
    expect(Product::count())->toBe(0);
});
