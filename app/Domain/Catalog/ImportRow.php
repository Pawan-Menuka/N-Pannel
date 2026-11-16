<?php

namespace App\Domain\Catalog;

final readonly class ImportRow
{
    public const array Columns = [
        'category_slug', 'product_slug', 'title_en', 'title_si', 'title_ta', 'summary_en', 'brand', 'product_active',
        'sku', 'label', 'colour', 'length_mm', 'width_mm', 'thickness_mm', 'weight_grams', 'sale_unit',
        'pieces_per_unit', 'price_lkr', 'trade_price_lkr', 'track_stock', 'reorder_level', 'stock_status', 'variant_active',
    ];

    /** @param array<string, mixed> $product
     * @param  array<string, mixed>  $variant
     */
    public function __construct(public int $number, public string $productSlug, public array $product, public array $variant) {}
}
