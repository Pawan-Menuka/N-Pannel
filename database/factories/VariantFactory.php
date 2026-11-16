<?php

namespace Database\Factories;

use App\Enums\SaleUnit;
use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Variant> */
class VariantFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => fake()->unique()->bothify('PVC-####??'),
            'label' => '3000 × 200 mm, white',
            'colour' => 'White',
            'length_mm' => 3000,
            'width_mm' => 200,
            'thickness_micron' => 7500,
            'weight_grams' => 1800,
            'sale_unit' => SaleUnit::Panel,
            'pieces_per_unit' => 1,
            'price_cents' => 125000,
            'trade_price_cents' => 115000,
            'last_cost_cents' => 90000,
            'track_stock' => true,
            'reorder_level' => 10,
            'stock_status' => StockStatus::InStock,
            'is_active' => true,
        ];
    }
}
