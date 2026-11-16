<?php

namespace Database\Factories;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PriceTier;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reference' => fake()->unique()->bothify('Q-########??'),
            'customer_id' => Customer::factory(),
            'location_id' => Location::factory(),
            'source' => OrderSource::Quote,
            'status' => OrderStatus::Quoted,
            'payment_state' => PaymentState::Unpaid,
            'price_tier' => PriceTier::Retail,
            'locale' => 'en',
            'customer_name' => fn (array $attributes) => $attributes['customer_id'] === null
                ? fake()->randomElement(['Nimal Perera', 'Kumari Fernando', 'Suresh Rajan'])
                : Customer::findOrFail($attributes['customer_id'])->name,
            'phone_e164' => fn (array $attributes) => $attributes['customer_id'] === null
                ? fake()->numerify('+947########')
                : Customer::findOrFail($attributes['customer_id'])->phone_e164,
            'subtotal_cents' => 125000,
            'total_cents' => 125000,
            'paid_cents' => 0,
            'refunded_cents' => 0,
            'created_by_id' => User::factory(),
        ];
    }
}
