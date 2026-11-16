<?php

namespace Database\Factories;

use App\Enums\StockReason;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockMovement> */
class StockMovementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'variant_id' => Variant::factory(),
            'location_id' => Location::factory(),
            'delta' => 10,
            'qty_after' => 10,
            'reason' => StockReason::ManualAdjust,
            'note' => 'Opening count adjustment',
            'is_override' => false,
            'user_id' => User::factory(),
        ];
    }
}
