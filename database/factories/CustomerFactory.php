<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Enums\PriceTier;
use App\Models\Customer;
use App\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'phone_e164' => fake()->unique()->numerify('+947########'),
            'name' => fake()->randomElement(['Nimal Perera', 'Kumari Fernando', 'Suresh Rajan', 'Ayesha Silva']),
            'type' => CustomerType::Homeowner,
            'price_tier' => PriceTier::Retail,
            'district_id' => District::factory(),
            'address_line1' => '24 Temple Road',
            'city' => 'Colombo',
            'preferred_locale' => 'en',
            'tags' => [],
            'name_edited_by_staff' => false,
            'marketing_opt_in' => false,
            'is_walk_in' => false,
        ];
    }
}
