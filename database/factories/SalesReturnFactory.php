<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\SalesReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesReturn> */
class SalesReturnFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'reason' => 'Incorrect size',
            'total_cents' => 125000,
            'refund_cents' => 125000,
            'created_by_id' => User::factory(),
        ];
    }
}
