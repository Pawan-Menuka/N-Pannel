<?php

namespace Database\Factories;

use App\Models\OrderLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesReturnLine> */
class SalesReturnLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_line_id' => OrderLine::factory(),
            'sales_return_id' => fn (array $attributes) => SalesReturn::factory()->create(['order_id' => OrderLine::findOrFail($attributes['order_line_id'])->order_id])->id,
            'variant_id' => fn (array $attributes) => OrderLine::findOrFail($attributes['order_line_id'])->variant_id,
            'qty' => 1,
            'restock' => true,
            'unit_price_cents' => 125000,
            'line_total_cents' => 125000,
        ];
    }
}
