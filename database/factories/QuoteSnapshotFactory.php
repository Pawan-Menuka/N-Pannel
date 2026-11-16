<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\QuoteSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuoteSnapshot> */
class QuoteSnapshotFactory extends Factory
{
    /** @var array<int, int> */
    private array $lastRevisionByOrder = [];

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'revision' => fn (array $attributes): int => $this->nextRevision((int) $attributes['order_id']),
            'lines' => [['kind' => 'product', 'description' => 'PVC ceiling panel', 'sale_unit' => 'panel', 'qty' => 1, 'unit_price_cents' => 125000, 'line_total_cents' => 125000]],
            'total_cents' => 125000,
            'required_advance_cents' => 62500,
            'valid_until' => now()->addDays(7),
            'created_by_id' => User::factory(),
        ];
    }

    private function nextRevision(int $orderId): int
    {
        $latestPersistedRevision = (int) QuoteSnapshot::where('order_id', $orderId)->max('revision');

        return $this->lastRevisionByOrder[$orderId] = max(
            $this->lastRevisionByOrder[$orderId] ?? 0,
            $latestPersistedRevision,
        ) + 1;
    }
}
