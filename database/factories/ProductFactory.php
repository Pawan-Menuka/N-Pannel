<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->bothify('pvc-panel-####??'),
            'category_id' => Category::factory(),
            'brand' => 'N-Pannel',
            'title' => ['en' => 'PVC ceiling panel', 'si' => 'PVC ceiling panel', 'ta' => 'PVC ceiling panel'],
            'summary' => ['en' => 'White ceiling panel', 'si' => 'White ceiling panel', 'ta' => 'White ceiling panel'],
            'description' => ['en' => 'PVC panel for indoor ceilings', 'si' => 'PVC panel for indoor ceilings', 'ta' => 'PVC panel for indoor ceilings'],
        ];
    }
}
