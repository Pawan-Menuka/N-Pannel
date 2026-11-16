<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Lanka Building Supplies', 'Colombo Ceiling Traders', 'Kandy Hardware']),
            'phone_e164' => fake()->unique()->numerify('+947########'),
            'email' => fake()->safeEmail(),
            'address' => '45 Galle Road, Colombo',
            'is_active' => true,
        ];
    }
}
