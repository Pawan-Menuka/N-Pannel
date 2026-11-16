<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\SalesReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditNote> */
class CreditNoteFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'order_id' => fn (array $attributes) => Invoice::findOrFail($attributes['invoice_id'])->order_id,
            'customer_id' => fn (array $attributes) => Invoice::findOrFail($attributes['invoice_id'])->customer_id,
            'sales_return_id' => fn (array $attributes) => SalesReturn::factory()->create(['order_id' => $attributes['order_id']])->id,
            'number' => fake()->unique()->numerify('CRN-2026-#####'),
            'status' => DocumentStatus::Issued,
            'customer_snapshot' => fn (array $attributes) => Invoice::findOrFail($attributes['invoice_id'])->customer_snapshot,
            'lines' => [['description' => 'Returned PVC ceiling panel', 'qty' => 1, 'unit_price_cents' => 125000, 'line_total_cents' => 125000]],
            'total_cents' => 125000,
            'issued_on' => now()->toDateString(),
            'issued_by_id' => User::factory(),
        ];
    }
}
