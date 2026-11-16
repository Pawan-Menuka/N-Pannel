<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoodsReceiptLine> */
class GoodsReceiptLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'goods_receipt_id' => GoodsReceipt::factory(),
            'variant_id' => Variant::factory(),
            'qty' => 10,
            'unit_cost_cents' => 90000,
            'line_total_cents' => 900000,
            'sort_order' => 0,
        ];
    }
}
