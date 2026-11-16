<?php

namespace App\Actions\Catalog;

use App\Domain\Catalog\CatalogFormat;
use App\Domain\Catalog\ImportReport;
use App\Domain\Catalog\ImportRow;
use App\Domain\Catalog\Measure;
use App\Domain\Money\InvalidAmount;
use App\Domain\Money\Lkr;
use App\Enums\SaleUnit;
use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use RuntimeException;

class ImportProducts
{
    public function run(string $path, bool $commit, User $actor): ImportReport
    {
        Gate::forUser($actor)->authorize('create', Product::class);

        $execute = function () use ($path, $commit): ImportReport {
            $report = new ImportReport;
            if (! $this->withinRowLimit($path, $report)) {
                return $report;
            }
            $categories = Category::query()->pluck('id', 'slug')->all();
            $productRows = [];
            $skus = [];
            $prepared = [];

            $present = [];
            $batches = LazyCollection::make(function () use ($path, $report, &$present): Generator {
                yield from $this->read($path, $report, $present);
            })->chunk(100);
            foreach ($batches as $batch) {
                $products = Product::query()->whereIn('slug', $batch->pluck('product_slug')->filter(fn (string $slug): bool => mb_check_encoding($slug, 'UTF-8'))->all())
                    ->when($commit, fn ($query) => $query->lockForUpdate())->get()->keyBy('slug');
                $variants = Variant::query()->with('product')
                    ->whereIn(DB::raw('UPPER(TRIM(sku))'), $batch->pluck('sku')->filter(fn (string $sku): bool => mb_check_encoding($sku, 'UTF-8'))
                        ->map(fn (string $sku): string => mb_strtoupper(trim($sku)))->all())
                    ->when($commit, fn ($query) => $query->lockForUpdate())->get()
                    ->keyBy(fn (Variant $variant): string => mb_strtoupper(trim($variant->sku)));
                foreach ($batch as $number => $row) {
                    $errorsBeforeRow = count($report->errors);
                    $parsed = $this->validateRow($number, $row, $categories, $present, $report);
                    $slug = $row['product_slug'];
                    $sku = $parsed->variant['sku'];
                    if (isset($skus[$sku])) {
                        $report->error($number, 'sku', 'Duplicate SKU within this file');
                    }
                    $skus[$sku] = true;
                    $existingVariant = $variants->get($sku);
                    if ($existingVariant && $existingVariant->product->slug !== $slug) {
                        $report->error($number, 'sku', 'SKU already belongs to a different product');
                    }
                    $firstProductRow = ! isset($productRows[$slug]);
                    if (! $firstProductRow) {
                        foreach (['category_slug', 'title_en', 'title_si', 'title_ta', 'summary_en', 'brand', 'product_active'] as $column) {
                            if (! isset($present[$column])) {
                                continue;
                            }
                            $firstValue = $productRows[$slug][$column];
                            $value = $row[$column];
                            if ($column === 'product_active') {
                                $firstValue = $this->boolean($firstValue, $number, $column, $report);
                                $value = $this->boolean($value, $number, $column, $report);
                            }
                            if ($firstValue !== $value) {
                                $report->error($number, $column, 'Product values disagree with another row for this product_slug');
                            }
                        }
                    } else {
                        $productRows[$slug] = array_intersect_key($row, array_flip(['category_slug', 'title_en', 'title_si', 'title_ta', 'summary_en', 'brand', 'product_active']));
                    }
                    if (count($report->errors) > $errorsBeforeRow) {
                        $prepared = [];

                        continue;
                    }
                    if ($existingVariant) {
                        $parsed = new ImportRow($parsed->number, $parsed->productSlug, $parsed->product,
                            array_replace($parsed->variant, ['sku' => $existingVariant->sku]));
                    }
                    if ($firstProductRow) {
                        $product = $products->get($slug);
                        $candidate = $product ? clone $product : new Product;
                        $this->fillProduct($candidate, $parsed->product);
                        foreach (['si' => 'Sinhala', 'ta' => 'Tamil'] as $locale => $language) {
                            if (! isset($present['title_'.$locale]) && ($candidate->getTranslations('title')[$locale] ?? '') === '') {
                                $report->warning($number, 'Missing '.$language.' title');
                            }
                        }
                        if (! $product) {
                            $report->productsToCreate++;
                        } elseif ($this->productChanged($product, $candidate)) {
                            $report->productsToUpdate++;
                        } else {
                            $report->productsUnchanged++;
                        }
                    }
                    $candidate = $existingVariant ? clone $existingVariant : new Variant;
                    $candidate->fill($parsed->variant);
                    if (! isset($present['weight_grams']) && $candidate->weight_grams === null) {
                        $report->warning($number, 'Missing weight');
                    }
                    if ($candidate->trade_price_cents !== null && $candidate->trade_price_cents > $candidate->price_cents) {
                        $report->error($number, 'trade_price_lkr', 'Trade price must not exceed price');
                        $prepared = [];

                        continue;
                    }
                    if (! $existingVariant) {
                        $report->variantsToCreate++;
                    } elseif ($candidate->isDirty()) {
                        $report->variantsToUpdate++;
                    } else {
                        $report->variantsUnchanged++;
                    }
                    if ($commit && $report->isClean()) {
                        $prepared[] = $parsed;
                    }
                }
            }

            if (! $commit || ! $report->isClean()) {
                return $report;
            }

            foreach ($prepared as $row) {
                $product = Product::query()->where('slug', $row->productSlug)->first();
                if (! $product) {
                    $product = new Product;
                    $this->fillProduct($product, $row->product);
                    $product->save();
                } else {
                    $candidate = clone $product;
                    $this->fillProduct($candidate, $row->product);
                    if ($this->productChanged($product, $candidate)) {
                        $this->fillProduct($product, $row->product);
                        $product->save();
                    }
                }
                $variant = Variant::query()->where('sku', $row->variant['sku'])->first() ?? new Variant;
                $variant->fill($row->variant);
                $variant->product()->associate($product);
                if (! $variant->exists || $variant->isDirty()) {
                    $variant->save();
                }
            }
            $report->committed = true;

            return $report;
        };

        return $commit ? DB::transaction($execute) : $execute();
    }

    private function withinRowLimit(string $path, ImportReport $report): bool
    {
        $reader = new CsvReader;
        $header = true;
        $count = 0;
        try {
            foreach ($reader->records($path) as $line => $values) {
                if ($header) {
                    $header = false;

                    continue;
                }
                if ($reader->isBlank($values)) {
                    continue;
                }
                $count++;
                if ($count > 5000) {
                    $report->rowsRead = $count;
                    $report->error($line, 'file', 'A file may contain at most 5,000 rows');

                    return false;
                }
            }

            return true;
        } catch (RuntimeException $exception) {
            $report->error(1, 'file', $exception->getMessage());

            return false;
        }
    }

    /**
     * @param  array<string, int>  $present
     * @return Generator<int, array<string, string>>
     */
    private function read(string $path, ImportReport $report, array &$present): Generator
    {
        $reader = new CsvReader;
        $header = null;
        foreach ($reader->records($path) as $number => $values) {
            if ($header === null) {
                if ($reader->isBlank($values)) {
                    $report->error(1, 'header', 'A header row is required');

                    return;
                }
                $header = array_map(fn (?string $value): string => trim($value ?? ''), $values);
                $present = array_flip($header);
                foreach ($header as $column) {
                    if (! in_array($column, ImportRow::Columns, true)) {
                        $report->error(1, $column, 'Unknown header');
                    }
                }
                if (count(array_unique($header)) !== count($header)) {
                    $report->error(1, 'header', 'Duplicate headers are not allowed');
                }
                foreach (['category_slug', 'product_slug', 'title_en', 'sku', 'sale_unit', 'price_lkr'] as $column) {
                    if (! isset($present[$column])) {
                        $report->error(1, $column, 'Required header is missing');
                    }
                }

                continue;
            }
            if ($reader->isBlank($values)) {
                continue;
            }
            $report->rowsRead++;
            if ($report->rowsRead > 5000) {
                $report->error($number, 'file', 'A file may contain at most 5,000 rows');
                break;
            }
            if (count($values) !== count($header)) {
                $report->error($number, 'row', 'The number of values must match the header');

                continue;
            }
            $row = array_fill_keys(ImportRow::Columns, '');
            $validEncoding = true;
            foreach ($values as $index => $value) {
                if (! mb_check_encoding($value ?? '', 'UTF-8')) {
                    $report->error($number, $header[$index], 'Text must be UTF-8');
                    $validEncoding = false;
                }
                $value = CatalogFormat::importCell($value ?? '');
                $row[$header[$index]] = in_array($header[$index], ['title_en', 'title_si', 'title_ta', 'summary_en', 'brand', 'label', 'colour'], true)
                    ? $value : trim($value);
            }
            if (! $validEncoding) {
                continue;
            }
            yield $number => $row;
        }
        if ($header === null) {
            $report->error(1, 'header', 'A header row is required');
        }
    }

    /** @param array<string, string> $row
     * @param  array<string, int>  $categories
     * @param  array<string, int>  $present
     */
    private function validateRow(int $number, array $row, array $categories, array $present, ImportReport $report): ImportRow
    {
        foreach (['category_slug', 'product_slug', 'title_en', 'sku', 'sale_unit', 'price_lkr'] as $column) {
            if (trim($row[$column]) === '') {
                $report->error($number, $column, 'A value is required');
            }
        }
        $row['sku'] = mb_strtoupper($row['sku']);
        foreach (['category_slug', 'product_slug', 'title_en', 'title_si', 'title_ta', 'brand', 'label', 'colour'] as $column) {
            if (mb_strlen($row[$column]) > 255) {
                $report->error($number, $column, 'Must not exceed 255 characters');
            }
        }
        foreach (['category_slug', 'product_slug'] as $column) {
            if (! preg_match(CatalogFormat::SlugPattern, $row[$column])) {
                $report->error($number, $column, 'Use lowercase letters and numbers separated by single hyphens');
            }
        }
        if (mb_strlen($row['sku']) > 64 || ! preg_match(CatalogFormat::SkuPattern, $row['sku'])) {
            $report->error($number, 'sku', 'Use at most 64 letters, numbers, dots, underscores, slashes or hyphens, starting with a letter or number');
        }
        if (! isset($categories[$row['category_slug']])) {
            $report->error($number, 'category_slug', 'Unknown category slug');
        }
        if (! SaleUnit::tryFrom($row['sale_unit'])) {
            $report->error($number, 'sale_unit', 'Unknown sale unit');
        }
        $status = $row['stock_status'] === '' ? StockStatus::InStock->value : $row['stock_status'];
        if (! StockStatus::tryFrom($status)) {
            $report->error($number, 'stock_status', 'Unknown stock status');
        }
        $price = $this->money($row['price_lkr'], $number, 'price_lkr', $report);
        if ($price !== null && $price <= 0) {
            $report->error($number, 'price_lkr', 'Price must be greater than zero');
        }
        $trade = $row['trade_price_lkr'] === '' ? null : $this->money($row['trade_price_lkr'], $number, 'trade_price_lkr', $report);
        if ($trade !== null && $price !== null && $trade > $price) {
            $report->error($number, 'trade_price_lkr', 'Trade price must not exceed price');
        }
        $thickness = null;
        if ($row['thickness_mm'] !== '') {
            try {
                $thickness = Measure::parseMillimetresToMicrons($row['thickness_mm']);
            } catch (InvalidArgumentException $exception) {
                $report->error($number, 'thickness_mm', $exception->getMessage());
            }
        }
        $integers = [];
        foreach (['length_mm', 'width_mm', 'weight_grams', 'reorder_level', 'pieces_per_unit'] as $column) {
            $value = $row[$column];
            if ($value === '') {
                $integers[$column] = $column === 'pieces_per_unit' ? 1 : null;
            } elseif (! ctype_digit($value) || strlen(ltrim($value, '0')) > 10 || (int) $value > 2147483647 || ($column === 'pieces_per_unit' && (int) $value < 1)) {
                $report->error($number, $column, $column === 'pieces_per_unit' ? 'Must be an integer of at least 1' : 'Must be a non-negative integer no larger than 2147483647');
                $integers[$column] = null;
            } else {
                $integers[$column] = (int) $value;
            }
        }
        foreach (['title_si' => 'Sinhala', 'title_ta' => 'Tamil'] as $column => $language) {
            if (! isset($present[$column])) {
                continue;
            }
            if ($row[$column] === '') {
                $report->warning($number, 'Missing '.$language.' title');
            }
            if (str_contains($row[$column], '??') || str_contains($row[$column], "\u{FFFD}")) {
                $report->warning($number, $language.' title looks like the file lost Sinhala/Tamil characters — was it re-saved as plain CSV?');
            }
        }
        if (isset($present['weight_grams']) && $row['weight_grams'] === '') {
            $report->warning($number, 'Missing weight');
        }

        $product = [
            'slug' => $row['product_slug'], 'category_id' => $categories[$row['category_slug']] ?? null,
            'title' => ['en' => $row['title_en'], 'si' => $row['title_si'], 'ta' => $row['title_ta']],
            'summary' => ['en' => $row['summary_en']], 'brand' => $row['brand'] === '' ? null : $row['brand'],
            'is_active' => $this->boolean($row['product_active'], $number, 'product_active', $report),
        ];
        $product['title'] = array_intersect_key($product['title'], array_flip(array_filter(['en', 'si', 'ta'], fn (string $locale): bool => isset($present['title_'.$locale]))));
        if (! isset($present['summary_en'])) {
            unset($product['summary']);
        }
        foreach (['brand' => 'brand', 'is_active' => 'product_active'] as $attribute => $column) {
            if (! isset($present[$column])) {
                unset($product[$attribute]);
            }
        }
        $variant = [
            ...$integers, 'sku' => $row['sku'], 'label' => $row['label'] === '' ? null : $row['label'], 'colour' => $row['colour'] === '' ? null : $row['colour'],
            'thickness_micron' => $thickness, 'sale_unit' => $row['sale_unit'], 'price_cents' => $price,
            'trade_price_cents' => $trade, 'stock_status' => $status,
            'track_stock' => $this->boolean($row['track_stock'], $number, 'track_stock', $report),
            'is_active' => $this->boolean($row['variant_active'], $number, 'variant_active', $report),
        ];
        foreach ([
            'label' => 'label', 'colour' => 'colour', 'length_mm' => 'length_mm', 'width_mm' => 'width_mm',
            'thickness_micron' => 'thickness_mm', 'weight_grams' => 'weight_grams', 'pieces_per_unit' => 'pieces_per_unit',
            'trade_price_cents' => 'trade_price_lkr', 'track_stock' => 'track_stock', 'reorder_level' => 'reorder_level',
            'stock_status' => 'stock_status', 'is_active' => 'variant_active',
        ] as $attribute => $column) {
            if (! isset($present[$column])) {
                unset($variant[$attribute]);
            }
        }

        return new ImportRow($number, $row['product_slug'], $product, $variant);
    }

    private function boolean(string $value, int $row, string $column, ImportReport $report): bool
    {
        $normalized = strtolower($value);
        if (in_array($normalized, ['', 'yes', 'true', '1'], true)) {
            return true;
        }
        if (in_array($normalized, ['no', 'false', '0'], true)) {
            return false;
        }
        $report->error($row, $column, 'Use yes/no, true/false or 1/0');

        return true;
    }

    private function money(string $value, int $row, string $column, ImportReport $report): ?int
    {
        try {
            return Lkr::parse($value);
        } catch (InvalidAmount $exception) {
            $report->error($row, $column, $exception->getMessage());

            return null;
        }
    }

    /** @param array<string, mixed> $data */
    private function fillProduct(Product $product, array $data): void
    {
        foreach (['title', 'summary'] as $attribute) {
            if (! isset($data[$attribute])) {
                continue;
            }
            foreach ($data[$attribute] as $locale => $value) {
                if ($value === '') {
                    $product->forgetTranslation($attribute, $locale);
                } else {
                    $product->setTranslation($attribute, $locale, $value);
                }
            }
            unset($data[$attribute]);
        }
        $product->fill($data);
    }

    private function productChanged(Product $original, Product $candidate): bool
    {
        foreach (['title', 'summary'] as $attribute) {
            if ($original->getTranslations($attribute) != $candidate->getTranslations($attribute)) {
                return true;
            }
        }

        return $candidate->isDirty(['slug', 'category_id', 'brand', 'is_active']);
    }
}
