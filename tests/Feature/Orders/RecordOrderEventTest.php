<?php

use App\Actions\Orders\RecordOrderEvent;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

it('records a status event with both statuses note and staff member', function () {
    $order = Order::factory()->create();
    $by = User::factory()->sales()->create();
    $event = app(RecordOrderEvent::class)->handle($order, OrderEventType::Status, OrderStatus::Quoted, OrderStatus::Confirmed, 'Accepted by phone', $by);

    $this->assertDatabaseHas('order_events', [
        'id' => $event->id, 'order_id' => $order->id, 'type' => 'status',
        'from_status' => 'quoted', 'to_status' => 'confirmed',
        'note' => 'Accepted by phone', 'user_id' => $by->id,
    ]);
});

it('allows system events without a user note or starting status', function () {
    $order = Order::factory()->create();
    $event = app(RecordOrderEvent::class)->handle($order, OrderEventType::Status, to: OrderStatus::Quoted);

    $this->assertDatabaseHas('order_events', [
        'id' => $event->id, 'order_id' => $order->id, 'from_status' => null,
        'to_status' => 'quoted', 'user_id' => null, 'note' => null,
    ]);
});
