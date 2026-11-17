<?php

namespace App\Actions\Orders;

use App\Enums\OrderEventType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ClearAttention
{
    public function __construct(private RecordOrderEvent $events) {}

    public function handle(Order $order, string $note, User $by): Order
    {
        $order = Order::query()->findOrFail($order->id);
        $this->validate($order);
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 255) {
            throw ValidationException::withMessages(['note' => 'A resolution note of at most 255 characters is required.']);
        }
        Gate::forUser($by)->authorize('clearAttention', $order);

        return DB::transaction(function () use ($order, $note, $by): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->validate($locked);
            $locked->update(['needs_attention' => false, 'attention_note' => null]);
            $this->events->handle($locked, OrderEventType::Attention, note: mb_substr('Attention cleared: '.$note, 0, 255), by: $by);

            return $locked->refresh();
        });
    }

    private function validate(Order $order): void
    {
        if (! $order->needs_attention) {
            throw ValidationException::withMessages(['needs_attention' => 'This order is not flagged.']);
        }
    }
}
