<?php

namespace Database\Factories;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderEvent> */
class OrderEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'type' => OrderEventType::Status,
            'to_status' => OrderStatus::Quoted,
            'user_id' => User::factory(),
            'note' => 'Quote created',
        ];
    }
}
