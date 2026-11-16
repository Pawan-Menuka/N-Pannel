<?php

namespace Database\Factories;

use App\Enums\OrderLineKind;
use App\Enums\SaleUnit;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderLine> */
class OrderLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'variant_id' => Variant::factory(),
            'kind' => OrderLineKind::Product,
            'sku' => fn (array $attributes) => Variant::findOrFail($attributes['variant_id'])->sku,
            'description' => 'PVC ceiling panel — 3000 mm',
            'sale_unit' => SaleUnit::Panel,
            'qty' => 1,
            'unit_price_cents' => 125000,
            'unit_cost_cents' => 90000,
            'line_total_cents' => 125000,
            'sort_order' => 0,
        ];
    }
}
