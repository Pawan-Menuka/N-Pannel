<?php

use App\Actions\Orders\AppendAttentionNote;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

it('retains the end of existing attention when the oldest text must be trimmed', function () {
    $order = Order::factory()->create(['attention_note' => str_repeat('x', 250).'TAIL!']);
    DB::transaction(function () use ($order): void {
        app(AppendAttentionNote::class)->handle(Order::query()->lockForUpdate()->findOrFail($order->id), 'New note', null);
    });
    expect($order->fresh()->attention_note)->toBe('… '.str_repeat('x', 239).'TAIL! New note');
    expect($order->fresh()->needs_attention)->toBeTrue();
    expect($order->events()->sole()->note)->toBe('New note');
});

it('prioritizes a long new note and caps both the attention and its event', function (int $length) {
    $order = Order::factory()->create(['attention_note' => 'Old attention']);
    DB::transaction(function () use ($order, $length): void {
        app(AppendAttentionNote::class)->handle(Order::query()->lockForUpdate()->findOrFail($order->id), str_repeat('අ', $length), null);
    });
    $expected = str_repeat('අ', min($length, 255));
    expect($order->fresh()->attention_note)->toBe($expected);
    expect($order->events()->sole()->note)->toBe($expected);
})->with([252, 253, 254, 255, 256]);
