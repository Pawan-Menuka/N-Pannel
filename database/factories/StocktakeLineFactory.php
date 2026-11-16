<?php

namespace Database\Factories;

use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StocktakeLine> */
class StocktakeLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'stocktake_id' => Stocktake::factory(),
            'variant_id' => Variant::factory(),
            'counted_qty' => 10,
        ];
    }
}
