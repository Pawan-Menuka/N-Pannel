<?php

namespace App\Actions\Orders;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;

final class RecordOrderEvent
{
    public function handle(Order $order, OrderEventType $type, ?OrderStatus $from = null, ?OrderStatus $to = null, ?string $note = null, ?User $by = null): OrderEvent
    {
        return $order->events()->create([
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'user_id' => $by?->id,
        ]);
    }
}
