<?php

namespace App\Actions\Catalog;

use App\Domain\Catalog\CatalogFormat;
use App\Domain\Catalog\ImportRow;
use App\Domain\Catalog\Measure;
use App\Domain\Money\Lkr;
use App\Models\Variant;
use RuntimeException;

class ExportProducts
{
    public function run(string $path): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write catalog export');
        }
        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ImportRow::Columns, ',', '"', '');
            foreach (Variant::query()->with('product.category')->orderBy('id')->lazy(500) as $variant) {
                $product = $variant->product;
                $row = [
                    'category_slug' => $product->category->slug, 'product_slug' => $product->slug,
                    'title_en' => $product->getTranslation('title', 'en', false),
                    'title_si' => $product->getTranslation('title', 'si', false),
                    'title_ta' => $product->getTranslation('title', 'ta', false),
                    'summary_en' => $product->getTranslation('summary', 'en', false), 'brand' => $product->brand,
                    'product_active' => $product->is_active ? 'yes' : 'no', 'sku' => $variant->sku,
                    'label' => $variant->label, 'colour' => $variant->colour, 'length_mm' => $variant->length_mm,
                    'width_mm' => $variant->width_mm,
                    'thickness_mm' => $variant->thickness_micron === null ? '' : Measure::micronsToMillimetres($variant->thickness_micron),
                    'weight_grams' => $variant->weight_grams, 'sale_unit' => $variant->sale_unit->value,
                    'pieces_per_unit' => $variant->pieces_per_unit, 'price_lkr' => Lkr::format($variant->price_cents),
                    'trade_price_lkr' => $variant->trade_price_cents === null ? '' : Lkr::format($variant->trade_price_cents),
                    'track_stock' => $variant->track_stock ? 'yes' : 'no', 'reorder_level' => $variant->reorder_level,
                    'stock_status' => $variant->stock_status->value, 'variant_active' => $variant->is_active ? 'yes' : 'no',
                ];
                fputcsv($handle, array_map(fn (string $column): mixed => CatalogFormat::exportCell($row[$column]), ImportRow::Columns), ',', '"', '');
            }
        } finally {
            fclose($handle);
        }
    }

    public function template(string $path): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write template');
        }
        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ImportRow::Columns, ',', '"', '');
            foreach (['NP-SAMPLE-8' => '2440', 'NP-SAMPLE-10' => '3048'] as $sku => $length) {
                $row = array_fill_keys(ImportRow::Columns, '');
                $row = array_replace($row, [
                    'category_slug' => 'ceilings', 'product_slug' => 'sample-ceiling-panel',
                    'title_en' => 'Sample ceiling panel', 'sku' => $sku, 'label' => $length.' mm',
                    'length_mm' => $length, 'width_mm' => '1220', 'thickness_mm' => '7.5',
                    'weight_grams' => '1800', 'sale_unit' => 'panel', 'pieces_per_unit' => '1',
                    'price_lkr' => '1,250.50', 'track_stock' => 'yes', 'stock_status' => 'in_stock',
                ]);
                fputcsv($handle, array_values($row), ',', '"', '');
            }
        } finally {
            fclose($handle);
        }
    }
}
