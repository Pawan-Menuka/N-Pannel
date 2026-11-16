<?php

namespace Database\Factories;

use App\Enums\StocktakeStatus;
use App\Models\Location;
use App\Models\Stocktake;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Stocktake> */
class StocktakeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'status' => StocktakeStatus::Draft,
            'created_by_id' => User::factory(),
            'note' => 'Monthly warehouse count',
        ];
    }
}
