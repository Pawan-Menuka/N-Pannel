<?php

use App\Actions\Stock\AddAllTrackedItems;
use App\Actions\Stock\CancelStocktake;
use App\Actions\Stock\CreateStocktake;
use App\Actions\Stock\DeleteStocktakeLine;
use App\Actions\Stock\ImportStocktakeCounts;
use App\Actions\Stock\PostStocktake;
use App\Actions\Stock\PreviewStocktake;
use App\Actions\Stock\ReserveStock;
use App\Actions\Stock\SaveStocktakeLine;
use App\Actions\Stock\UpdateStocktakeNote;
use App\Enums\StockReason;
use App\Enums\StocktakeStatus;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->location = Location::factory()->create(['is_default' => true]);
    $this->actor = User::factory()->stock()->create();
    $this->stocktake = app(CreateStocktake::class)->handle($this->actor, ' Opening ');
});

function stocktakeCsv(string $contents): string
{
    $path = 'counts-'.bin2hex(random_bytes(6)).'.csv';
    Storage::disk('local')->put($path, $contents);

    return Storage::disk('local')->path($path);
}

it('creates a draft at the default location', function () {
    expect($this->stocktake->status)->toBe(StocktakeStatus::Draft);
    expect($this->stocktake->location_id)->toBe($this->location->id);
    expect($this->stocktake->created_by_id)->toBe($this->actor->id);
    expect($this->stocktake->note)->toBe('Opening');
});

it('upserts counted lines while leaving expected quantities unset', function () {
    $variant = Variant::factory()->create();
    $first = app(SaveStocktakeLine::class)->handle($this->stocktake, $variant, 4, $this->actor);
    $second = app(SaveStocktakeLine::class)->handle($this->stocktake, $variant, 7, $this->actor);

    expect($second->id)->toBe($first->id);
    expect($second->counted_qty)->toBe(7);
    expect($second->expected_qty)->toBeNull();
    $this->assertDatabaseCount('stocktake_lines', 1);
});

it('validates tracked items and counted quantity boundaries', function (int $count, bool $tracked, string $field) {
    $variant = Variant::factory()->create(['track_stock' => $tracked]);
    try {
        app(SaveStocktakeLine::class)->handle($this->stocktake, $variant, $count, $this->actor);
        $this->fail('Expected validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('stocktake_lines', 0);
})->with([[-1, true, 'counted_qty'], [1000001, true, 'counted_qty'], [0, false, 'variant_id']]);

it('deletes a counted line from a draft', function () {
    $line = app(SaveStocktakeLine::class)->handle($this->stocktake, Variant::factory()->create(), 2, $this->actor);

    expect(app(DeleteStocktakeLine::class)->handle($line, $this->actor))->toBeTrue();
    $this->assertModelMissing($line);
    $this->assertDatabaseCount('stock_movements', 0);
});

it('adds only missing active tracked variants as uncounted lines', function () {
    $present = Variant::factory()->create();
    app(SaveStocktakeLine::class)->handle($this->stocktake, $present, 9, $this->actor);
    $stocked = Variant::factory()->create();
    $empty = Variant::factory()->create();
    StockLevel::factory()->create(['variant_id' => $stocked->id, 'location_id' => $this->location->id, 'qty' => 6]);
    Variant::factory()->create(['track_stock' => false]);
    Variant::factory()->create(['is_active' => false]);

    expect(app(AddAllTrackedItems::class)->handle($this->stocktake, $this->actor))->toBe(2);
    expect(app(AddAllTrackedItems::class)->handle($this->stocktake, $this->actor))->toBe(0);
    $this->assertDatabaseHas('stocktake_lines', ['variant_id' => $stocked->id, 'counted_qty' => null]);
    $this->assertDatabaseHas('stocktake_lines', ['variant_id' => $empty->id, 'counted_qty' => null]);
    $this->assertDatabaseHas('stocktake_lines', ['variant_id' => $present->id, 'counted_qty' => 9]);
});

it('reports every CSV error with physical line numbers and commits nothing', function () {
    Variant::factory()->create(['sku' => 'KNOWN']);
    Variant::factory()->create(['sku' => 'UNTRACKED', 'track_stock' => false]);
    $path = stocktakeCsv("\xEF\xBB\xBFSKU,COUNTED_QTY\n\nKNOWN,2\nUNKNOWN,1\nUNTRACKED,1\nKNOWN,3\nNEGATIVE,-1\nFRACTION,1.5\n");

    $report = app(ImportStocktakeCounts::class)->handle($this->stocktake, $path, $this->actor, true);

    expect(array_keys($report->errors))->toBe([4, 5, 6, 7, 8]);
    expect($report->rowsRead)->toBe(6);
    expect($report->rowsOk)->toBe(1);
    expect($report->committed)->toBeFalse();
    $this->assertDatabaseCount('stocktake_lines', 0);
});

it('adds uncounted lines without copying current stock outside the count range', function (int $qty) {
    Variant::factory()->create();
    $invalid = Variant::factory()->create(['sku' => 'INVALID']);
    StockLevel::factory()->create(['variant_id' => $invalid->id, 'location_id' => $this->location->id, 'qty' => $qty]);

    expect(app(AddAllTrackedItems::class)->handle($this->stocktake, $this->actor))->toBe(2);

    $this->assertDatabaseCount('stocktake_lines', 2);
    $this->assertDatabaseHas('stocktake_lines', ['variant_id' => $invalid->id, 'counted_qty' => null]);
    $this->assertDatabaseCount('stock_movements', 0);
})->with([-3, 1000001]);

it('rejects missing headers and unreadable files with a single file error', function (string $content) {
    $report = app(ImportStocktakeCounts::class)->handle($this->stocktake, stocktakeCsv($content), $this->actor, true);

    expect($report->errors)->toHaveCount(1);
    expect($report->committed)->toBeFalse();
    $this->assertDatabaseCount('stocktake_lines', 0);
})->with(['sku,wrong\nA,1', '']);

it('reports an unreadable import file', function () {
    $report = app(ImportStocktakeCounts::class)->handle($this->stocktake, Storage::disk('local')->path('missing.csv'), $this->actor, true);

    expect($report->errors)->toHaveCount(1);
    expect($report->committed)->toBeFalse();
});

it('rejects header only count imports in both dry run and commit', function (bool $commit) {
    $report = app(ImportStocktakeCounts::class)->handle($this->stocktake, stocktakeCsv("sku,counted_qty\n\n"), $this->actor, $commit);

    expect($report->errors)->toBe([1 => ['The file has no rows.']]);
    expect($report->committed)->toBeFalse();
    $this->assertDatabaseCount('stocktake_lines', 0);
})->with([false, true]);

it('enforces the maximum CSV row count without writing', function () {
    Variant::factory()->create(['sku' => 'A']);
    $report = app(ImportStocktakeCounts::class)->handle($this->stocktake, stocktakeCsv("sku,counted_qty\n".str_repeat("A,1\n", 5001)), $this->actor, true);

    expect($report->errors)->toHaveKey(5002);
    expect($report->errors[5002])->toContain('The file must contain at most 5,000 data rows.');
    $this->assertDatabaseCount('stocktake_lines', 0);
});

it('dry runs then commits case insensitive counts and upserts existing lines', function () {
    $variant = Variant::factory()->create(['sku' => 'PANEL']);
    app(SaveStocktakeLine::class)->handle($this->stocktake, $variant, 3, $this->actor);
    $path = stocktakeCsv("sku,counted_qty\npanel,12\n");

    $dry = app(ImportStocktakeCounts::class)->handle($this->stocktake, $path, $this->actor, false);
    expect($dry->isClean())->toBeTrue();
    expect($dry->rowsOk)->toBe(1);
    $this->assertDatabaseHas('stocktake_lines', ['variant_id' => $variant->id, 'counted_qty' => 3]);
    $report = app(ImportStocktakeCounts::class)->handle($this->stocktake, $path, $this->actor, true);

    expect($report->committed)->toBeTrue();
    $this->assertDatabaseCount('stocktake_lines', 1);
    $this->assertDatabaseHas('stocktake_lines', ['variant_id' => $variant->id, 'counted_qty' => 12, 'expected_qty' => null]);
});

it('rejects malformed counts and duplicate SKU case variants', function (string $rows, array $lines) {
    Variant::factory()->create(['sku' => 'A']);
    $report = app(ImportStocktakeCounts::class)->handle($this->stocktake, stocktakeCsv("sku,counted_qty\n".$rows), $this->actor, true);

    expect(array_keys($report->errors))->toBe($lines);
    $this->assertDatabaseCount('stocktake_lines', 0);
})->with([["A,-1\n", [2]], ["A,1.5\n", [2]], ["A,1000001\n", [2]], ["A,9999999999999999999999999999999\n", [2]], ["A,1\na,2\n", [3]]]);

it('posts using current stock and writes only non-zero tracked variances', function () {
    $changed = Variant::factory()->create();
    $same = Variant::factory()->create();
    $disabled = Variant::factory()->create();
    foreach ([$changed, $same, $disabled] as $variant) {
        StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $this->location->id, 'qty' => 5]);
        app(SaveStocktakeLine::class)->handle($this->stocktake, $variant, $variant->is($same) ? 5 : 2, $this->actor);
    }
    $disabled->update(['track_stock' => false]);
    StockLevel::query()->where('variant_id', $changed->id)->update(['qty' => 8]);
    expect(app(PreviewStocktake::class)->handle($this->stocktake))->toBe(['changes' => 1, 'uncounted' => 0]);

    $posted = app(PostStocktake::class)->handle($this->stocktake, $this->actor);

    expect($posted->status)->toBe(StocktakeStatus::Posted);
    expect($posted->posted_by_id)->toBe($this->actor->id);
    expect($posted->posted_at)->not->toBeNull();
    $this->assertDatabaseHas('stocktake_lines', ['variant_id' => $changed->id, 'expected_qty' => 8]);
    $this->assertDatabaseHas('stocktake_lines', ['variant_id' => $disabled->id, 'expected_qty' => null]);
    $this->assertDatabaseHas('stock_levels', ['variant_id' => $disabled->id, 'qty' => 5]);
    $movement = StockMovement::query()->sole();
    expect($movement->delta)->toBe(-6);
    expect($movement->qty_after)->toBe(2);
    expect($movement->reason)->toBe(StockReason::Stocktake);
    expect($movement->source->is($posted))->toBeTrue();
    expect($movement->user_id)->toBe($this->actor->id);
});

it('loads opening stock for brand new variants through import and posting', function () {
    $first = Variant::factory()->create(['sku' => 'FIRST']);
    $second = Variant::factory()->create(['sku' => 'SECOND']);
    app(ImportStocktakeCounts::class)->handle($this->stocktake, stocktakeCsv("sku,counted_qty\nFIRST,25\nSECOND,0\n"), $this->actor, true);

    app(PostStocktake::class)->handle($this->stocktake, $this->actor);

    $this->assertDatabaseHas('stock_levels', ['variant_id' => $first->id, 'qty' => 25]);
    $this->assertDatabaseHas('stock_levels', ['variant_id' => $second->id, 'qty' => 0]);
    $this->assertDatabaseCount('stock_movements', 1);
});

it('refuses posting an empty stocktake', function () {
    expect(fn () => app(PostStocktake::class)->handle($this->stocktake, $this->actor))->toThrow(ValidationException::class, 'Enter at least one count.');
    expect($this->stocktake->refresh()->status)->toBe(StocktakeStatus::Draft);
    $this->assertDatabaseCount('stock_movements', 0);
});

it('refuses posting when every line is uncounted', function () {
    Variant::factory()->create();
    app(AddAllTrackedItems::class)->handle($this->stocktake, $this->actor);

    expect(app(PreviewStocktake::class)->handle($this->stocktake))->toBe(['changes' => 0, 'uncounted' => 1]);
    expect(fn () => app(PostStocktake::class)->handle($this->stocktake, $this->actor))->toThrow(ValidationException::class, 'Enter at least one count.');
    $this->assertDatabaseCount('stock_levels', 0);
    $this->assertDatabaseCount('stock_movements', 0);
    expect($this->stocktake->refresh()->status)->toBe(StocktakeStatus::Draft);
});

it('leaves uncounted stock unchanged after intervening sales and creates no missing levels', function () {
    $sold = Variant::factory()->create();
    $missing = Variant::factory()->create();
    $counted = Variant::factory()->create();
    StockLevel::factory()->create(['variant_id' => $sold->id, 'location_id' => $this->location->id, 'qty' => 10]);
    app(AddAllTrackedItems::class)->handle($this->stocktake, $this->actor);
    app(ReserveStock::class)->handle($sold, 3, StockReason::CounterSale, $this->stocktake, $this->actor);
    app(SaveStocktakeLine::class)->handle($this->stocktake, $counted, 4, $this->actor);

    expect(app(PreviewStocktake::class)->handle($this->stocktake))->toBe(['changes' => 1, 'uncounted' => 2]);
    app(PostStocktake::class)->handle($this->stocktake, $this->actor);

    $this->assertDatabaseHas('stock_levels', ['variant_id' => $sold->id, 'qty' => 7]);
    $this->assertDatabaseMissing('stock_levels', ['variant_id' => $missing->id]);
    $this->assertDatabaseHas('stock_levels', ['variant_id' => $counted->id, 'qty' => 4]);
    $this->assertDatabaseHas('stocktake_lines', ['variant_id' => $sold->id, 'expected_qty' => null, 'counted_qty' => null]);
    $this->assertDatabaseCount('stock_movements', 2);
});

it('skips counted variants whose tracking was disabled without creating a stock level', function () {
    $disabled = Variant::factory()->create();
    app(SaveStocktakeLine::class)->handle($this->stocktake, $disabled, 5, $this->actor);
    $disabled->update(['track_stock' => false]);

    app(PostStocktake::class)->handle($this->stocktake, $this->actor);

    $this->assertDatabaseCount('stock_levels', 0);
    $this->assertDatabaseCount('stock_movements', 0);
    $this->assertDatabaseHas('stocktake_lines', ['variant_id' => $disabled->id, 'expected_qty' => null]);
});

it('rejects stocktake variances outside the movement integer range atomically', function () {
    $variant = Variant::factory()->create();
    StockLevel::factory()->create(['variant_id' => $variant->id, 'location_id' => $this->location->id, 'qty' => -2147483648]);
    $line = app(SaveStocktakeLine::class)->handle($this->stocktake, $variant, 0, $this->actor);

    expect(fn () => app(PostStocktake::class)->handle($this->stocktake, $this->actor))->toThrow(ValidationException::class, 'The stocktake variance exceeds the supported stock quantity range.');

    expect($this->stocktake->refresh()->status)->toBe(StocktakeStatus::Draft);
    expect($line->refresh()->expected_qty)->toBeNull();
    $this->assertDatabaseHas('stock_levels', ['variant_id' => $variant->id, 'qty' => -2147483648]);
    $this->assertDatabaseCount('stock_movements', 0);
});

it('rolls back nullable counts by replacing missing counts with zero and restores nullability', function () {
    $line = StocktakeLine::factory()->create(['stocktake_id' => $this->stocktake->id, 'counted_qty' => null]);
    $migration = require database_path('migrations/2026_11_17_000010_make_stocktake_line_counted_qty_nullable.php');

    try {
        $migration->down();

        expect($line->refresh()->counted_qty)->toBe(0);
        expect(fn () => DB::transaction(fn () => StocktakeLine::factory()->create(['stocktake_id' => $this->stocktake->id, 'counted_qty' => null])))->toThrow(QueryException::class);
    } finally {
        $migration->up();
    }
    $nullable = StocktakeLine::factory()->create(['stocktake_id' => $this->stocktake->id, 'counted_qty' => null]);
    expect($nullable->counted_qty)->toBeNull();
});

it('cancels without changing stock', function () {
    $cancelled = app(CancelStocktake::class)->handle($this->stocktake, $this->actor);

    expect($cancelled->status)->toBe(StocktakeStatus::Cancelled);
    $this->assertDatabaseCount('stock_movements', 0);
});

it('denies modifications of closed stocktakes even through stale draft models', function (string $close) {
    $variant = Variant::factory()->create();
    $line = app(SaveStocktakeLine::class)->handle($this->stocktake, $variant, 2, $this->actor);
    $stale = clone $this->stocktake;
    app($close)->handle($this->stocktake, $this->actor);
    $closed = $this->stocktake->fresh();

    expect(Gate::forUser($this->actor)->allows('update', $closed))->toBeFalse();
    expect(Gate::forUser($this->actor)->allows('delete', $line->fresh()))->toBeFalse();
    expect(fn () => app(SaveStocktakeLine::class)->handle($stale, $variant, 9, $this->actor))->toThrow(ValidationException::class, 'Only draft stocktakes can be changed.');
    expect(fn () => app(DeleteStocktakeLine::class)->handle($line, $this->actor))->toThrow(ValidationException::class, 'Only draft stocktakes can be changed.');
    expect(fn () => app(AddAllTrackedItems::class)->handle($stale, $this->actor))->toThrow(ValidationException::class, 'Only draft stocktakes can be changed.');
    expect(fn () => app(UpdateStocktakeNote::class)->handle($stale, 'Changed', $this->actor))->toThrow(ValidationException::class, 'Only draft stocktakes can be changed.');
    expect(fn () => app(PostStocktake::class)->handle($stale, $this->actor))->toThrow(ValidationException::class, 'Only draft stocktakes can be changed.');
    expect(fn () => app(CancelStocktake::class)->handle($stale, $this->actor))->toThrow(ValidationException::class, 'Only draft stocktakes can be changed.');
    expect(fn () => app(ImportStocktakeCounts::class)->handle($stale, stocktakeCsv("sku,counted_qty\n{$variant->sku},3\n"), $this->actor, true))->toThrow(ValidationException::class, 'Only draft stocktakes can be changed.');
    expect($line->refresh()->counted_qty)->toBe(2);
    expect($closed->note)->toBe('Opening');
})->with([PostStocktake::class, CancelStocktake::class]);

it('forbids sales from creating or counting stocktakes', function () {
    $sales = User::factory()->sales()->create();

    expect(fn () => app(CreateStocktake::class)->handle($sales, null))->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveStocktakeLine::class)->handle($this->stocktake, Variant::factory()->create(), 1, $sales))->toThrow(AuthorizationException::class);
    expect(fn () => app(AddAllTrackedItems::class)->handle($this->stocktake, $sales))->toThrow(AuthorizationException::class);
    expect(fn () => app(PostStocktake::class)->handle($this->stocktake, $sales))->toThrow(AuthorizationException::class);
    expect(fn () => app(CancelStocktake::class)->handle($this->stocktake, $sales))->toThrow(AuthorizationException::class);
    expect(fn () => app(UpdateStocktakeNote::class)->handle($this->stocktake, 'Changed', $sales))->toThrow(AuthorizationException::class);
    expect(fn () => app(ImportStocktakeCounts::class)->handle($this->stocktake, stocktakeCsv("sku,counted_qty\n"), $sales, false))->toThrow(AuthorizationException::class);
});

it('saves trimmed draft notes and validates their length', function () {
    expect(app(UpdateStocktakeNote::class)->handle($this->stocktake, ' 0 ', $this->actor)->note)->toBe('0');
    expect(fn () => app(UpdateStocktakeNote::class)->handle($this->stocktake, str_repeat('a', 1001), $this->actor))->toThrow(ValidationException::class);
    expect(fn () => app(CreateStocktake::class)->handle($this->actor, str_repeat('a', 1001)))->toThrow(ValidationException::class);
});
