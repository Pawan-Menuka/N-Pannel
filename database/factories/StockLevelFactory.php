<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\StockLevel;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockLevel> */
class StockLevelFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'variant_id' => Variant::factory(),
            'location_id' => Location::factory(),
            'qty' => 0,
        ];
    }
}
