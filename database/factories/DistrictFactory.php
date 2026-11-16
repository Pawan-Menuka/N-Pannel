<?php

namespace Database\Factories;

use App\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<District> */
class DistrictFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->bothify('district-####??'),
            'name' => ['en' => 'Colombo', 'si' => 'Colombo', 'ta' => 'Colombo'],
            'province' => 'Western',
        ];
    }
}
