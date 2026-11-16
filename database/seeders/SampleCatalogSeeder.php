<?php

namespace Database\Seeders;

use App\Enums\SaleUnit;
use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Variant;
use Illuminate\Database\Seeder;

class SampleCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $location = Location::where('code', 'main')->firstOrFail();
        $products = [
            [
                'slug' => 'pvc-ceiling-panel-75mm', 'category' => 'ceilings', 'title' => 'PVC ceiling panel 7.5 mm',
                'unit' => SaleUnit::Panel, 'width_mm' => 200, 'thickness_micron' => 7500,
                'variants' => [
                    ['sku' => 'PVC-WHT-3000', 'label' => '3000 × 200 mm, white', 'length_mm' => 3000, 'price_cents' => 125000, 'last_cost_cents' => 90000],
                    ['sku' => 'PVC-WHT-4000', 'label' => '4000 × 200 mm, white', 'length_mm' => 4000, 'price_cents' => 165000, 'last_cost_cents' => 120000],
                ],
            ],
            [
                'slug' => 'gypsum-board-9mm', 'category' => 'ceilings', 'title' => 'Gypsum board 9 mm',
                'unit' => SaleUnit::Sheet, 'width_mm' => 1200, 'thickness_micron' => 9000,
                'variants' => [
                    ['sku' => 'GYP-9-2400', 'label' => '2400 × 1200 mm', 'length_mm' => 2400, 'price_cents' => 325000, 'last_cost_cents' => 250000],
                    ['sku' => 'GYP-9-1800', 'label' => '1800 × 1200 mm', 'length_mm' => 1800, 'price_cents' => 250000, 'last_cost_cents' => 190000],
                ],
            ],
            [
                'slug' => 'aluminium-t-grid-main-tee', 'category' => 'accessories', 'title' => 'Aluminium T-grid main tee',
                'unit' => SaleUnit::Piece, 'width_mm' => 24, 'thickness_micron' => null,
                'variants' => [
                    ['sku' => 'TEE-3000', 'label' => '3000 mm', 'length_mm' => 3000, 'price_cents' => 175000, 'last_cost_cents' => 125000],
                    ['sku' => 'TEE-3600', 'label' => '3600 mm', 'length_mm' => 3600, 'price_cents' => 210000, 'last_cost_cents' => 155000],
                ],
            ],
            [
                'slug' => 'ceiling-nails-box', 'category' => 'accessories', 'title' => 'Ceiling nails box',
                'unit' => SaleUnit::Box, 'width_mm' => null, 'thickness_micron' => null,
                'variants' => [
                    ['sku' => 'NAIL-25-BOX', 'label' => '25 mm, box of 100', 'length_mm' => 25, 'price_cents' => 65000, 'last_cost_cents' => 45000],
                    ['sku' => 'NAIL-38-BOX', 'label' => '38 mm, box of 100', 'length_mm' => 38, 'price_cents' => 85000, 'last_cost_cents' => 60000],
                ],
            ],
        ];

        foreach ($products as $data) {
            $product = Product::updateOrCreate(['slug' => $data['slug']], [
                'category_id' => Category::where('slug', $data['category'])->firstOrFail()->id,
                'title' => ['en' => $data['title'], 'si' => $data['title'], 'ta' => $data['title']],
                'is_active' => true,
            ]);

            foreach ($data['variants'] as $variantData) {
                $variant = Variant::updateOrCreate(['sku' => $variantData['sku']], [
                    ...$variantData,
                    'product_id' => $product->id,
                    'sale_unit' => $data['unit'],
                    'width_mm' => $data['width_mm'],
                    'thickness_micron' => $data['thickness_micron'],
                    'pieces_per_unit' => $data['unit'] === SaleUnit::Box ? 100 : 1,
                    'track_stock' => true,
                    'stock_status' => StockStatus::InStock,
                    'is_active' => true,
                ]);

                StockLevel::firstOrCreate([
                    'variant_id' => $variant->id,
                    'location_id' => $location->id,
                ], ['qty' => 0]);
            }
        }
    }
}
