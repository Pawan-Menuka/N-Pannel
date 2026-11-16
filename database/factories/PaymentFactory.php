<?php

namespace Database\Factories;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'kind' => PaymentKind::Payment,
            'method' => PaymentMethod::Cash,
            'status' => PaymentStatus::Pending,
            'amount_cents' => 125000,
            'created_by_id' => User::factory(),
        ];
    }
}
