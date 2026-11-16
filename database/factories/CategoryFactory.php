<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->bothify('ceilings-####??'),
            'name' => ['en' => 'Ceilings', 'si' => 'Ceilings', 'ta' => 'Ceilings'],
            'description' => ['en' => 'Ceiling materials', 'si' => 'Ceiling materials', 'ta' => 'Ceiling materials'],
        ];
    }
}
