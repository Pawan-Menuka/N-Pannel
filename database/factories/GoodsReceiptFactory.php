<?php

namespace Database\Factories;

use App\Enums\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoodsReceipt> */
class GoodsReceiptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'location_id' => Location::factory(),
            'status' => GoodsReceiptStatus::Draft,
            'received_on' => now()->toDateString(),
            'total_cost_cents' => 0,
            'created_by_id' => User::factory(),
        ];
    }
}
