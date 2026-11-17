<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numerify('INV-2026-#####'),
            'order_id' => Order::factory(),
            'customer_id' => fn (array $attributes) => Order::findOrFail($attributes['order_id'])->customer_id,
            'status' => DocumentStatus::Issued,
            'locale' => 'en',
            'customer_snapshot' => fn (array $attributes) => [
                'name' => Order::findOrFail($attributes['order_id'])->customer_name, 'company_name' => null,
                'phone_e164' => null, 'email' => null, 'address_line1' => '24 Temple Road', 'address_line2' => null,
                'city' => 'Colombo', 'vat_number' => null,
            ],
            'lines' => [[
                'kind' => 'product', 'sku' => 'PVC-PANEL', 'description' => 'PVC ceiling panel', 'sale_unit' => 'panel',
                'qty' => 1, 'unit_price_cents' => 125000, 'line_total_cents' => 125000, 'sort_order' => 0,
            ]],
            'subtotal_cents' => 125000,
            'total_cents' => 125000,
            'issued_on' => now()->toDateString(),
            'issued_by_id' => User::factory(),
        ];
    }
}
