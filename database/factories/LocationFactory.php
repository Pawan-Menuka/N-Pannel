<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Location> */
class LocationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('loc-####??'),
            'name' => fake()->randomElement(['Colombo warehouse', 'Kandy store', 'Galle depot']),
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
