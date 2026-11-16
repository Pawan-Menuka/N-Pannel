<?php

namespace App\Actions\Stock;

use App\Actions\Catalog\CsvReader;
use App\Domain\Stock\StocktakeImportReport;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ImportStocktakeCounts
{
    public function __construct(private CsvReader $csv, private LockDraftStocktake $lockDraft) {}

    public function handle(Stocktake $stocktake, string $path, User $actor, bool $commit): StocktakeImportReport
    {
        DB::transaction(fn (): Stocktake => $this->lockDraft->handle($stocktake, $actor));
        $report = new StocktakeImportReport;
        $headers = null;
        $seen = [];
        /** @var array<string, Variant|null> $variants */
        $variants = [];
        $rows = [];
        try {
            foreach ($this->csv->records($path) as $line => $values) {
                if ($this->csv->isBlank($values)) {
                    continue;
                }
                if ($headers === null) {
                    $headers = array_map(fn (?string $value): string => mb_strtolower(trim($value ?? '')), $values);
                    if (! in_array('sku', $headers, true) || ! in_array('counted_qty', $headers, true)) {
                        $report->error($line, 'The file must contain sku and counted_qty headers.');

                        return $report;
                    }

                    continue;
                }
                $report->rowsRead++;
                if ($report->rowsRead > 5000) {
                    $report->error($line, 'The file must contain at most 5,000 data rows.');
                    break;
                }
                $sku = mb_strtoupper(trim($values[array_search('sku', $headers, true)] ?? ''));
                $counted = trim($values[array_search('counted_qty', $headers, true)] ?? '');
                if (! array_key_exists($sku, $variants)) {
                    $variants[$sku] = Variant::query()->whereRaw('upper(sku) = ?', [$sku])->first();
                }
                $variant = $variants[$sku];
                if ($variant === null) {
                    $report->error($line, 'Unknown SKU: '.$sku.'.');
                } elseif (! $variant->track_stock) {
                    $report->error($line, "This item doesn't track stock.");
                }
                if (isset($seen[$sku])) {
                    $report->error($line, 'Duplicate SKU: '.$sku.'.');
                }
                $seen[$sku] = true;
                if (preg_match('/^[0-9]+$/D', $counted) !== 1 || strlen(ltrim($counted, '0')) > 7 || (int) $counted > 1000000) {
                    $report->error($line, 'Counted quantity must be a whole number between 0 and 1,000,000.');
                }
                if (! isset($report->errors[$line]) && $variant !== null) {
                    $rows[] = ['variant_id' => $variant->id, 'counted_qty' => (int) $counted];
                    $report->rowsOk++;
                }
            }
        } catch (RuntimeException $exception) {
            $report->error(1, $exception->getMessage());
        }
        if ($headers === null && $report->isClean()) {
            $report->error(1, 'The file must contain sku and counted_qty headers.');
        }
        if ($headers !== null && $report->rowsRead === 0 && $report->isClean()) {
            $report->error(1, 'The file has no rows.');
        }
        if (! $commit || ! $report->isClean()) {
            return $report;
        }

        DB::transaction(function () use ($stocktake, $actor, $rows): void {
            $locked = $this->lockDraft->handle($stocktake, $actor);
            foreach ($rows as $row) {
                $variant = Variant::query()->findOrFail($row['variant_id']);
                if (! $variant->track_stock) {
                    throw ValidationException::withMessages(['file' => "This item doesn't track stock."]);
                }
                StocktakeLine::query()->updateOrCreate(
                    ['stocktake_id' => $locked->id, 'variant_id' => $variant->id],
                    ['counted_qty' => $row['counted_qty'], 'expected_qty' => null],
                );
            }
        });
        $report->committed = true;

        return $report;
    }
}
