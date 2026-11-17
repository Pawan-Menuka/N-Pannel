<?php

use App\Actions\Orders\ClearAttention;
use App\Enums\OrderEventType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

it('clears attention and records who resolved it', function () {
    $order = Order::factory()->create(['needs_attention' => true, 'attention_note' => 'Check stock.']);
    $by = User::factory()->sales()->create();
    $result = app(ClearAttention::class)->handle($order, ' Stock adjusted ', $by);
    expect($result->needs_attention)->toBeFalse();
    expect($result->attention_note)->toBeNull();
    $event = $result->events()->sole();
    expect($event->type)->toBe(OrderEventType::Attention);
    expect($event->note)->toBe('Attention cleared: Stock adjusted');
    expect($event->user_id)->toBe($by->id);
});

it('refuses an unflagged order before the gate', function () {
    $order = Order::factory()->create();
    expect(fn () => app(ClearAttention::class)->handle($order, 'Checked', User::factory()->stock()->create()))
        ->toThrow(ValidationException::class, 'This order is not flagged.');
});

it('requires a short resolution note', function (string $note) {
    $order = Order::factory()->create(['needs_attention' => true]);
    expect(fn () => app(ClearAttention::class)->handle($order, $note, User::factory()->sales()->create()))->toThrow(ValidationException::class);
    expect($order->fresh()->needs_attention)->toBeTrue();
})->with([' ', str_repeat('x', 256)]);

it('refuses stock staff clearing attention', function () {
    $order = Order::factory()->create(['needs_attention' => true]);
    expect(fn () => app(ClearAttention::class)->handle($order, 'Checked', User::factory()->stock()->create()))->toThrow(AuthorizationException::class);
});

it('rechecks the attention flag under the order lock', function () {
    $order = Order::factory()->create(['needs_attention' => true]);
    Gate::after(function (User $user, string $ability) use ($order): void {
        if ($ability === 'clearAttention') {
            Order::query()->whereKey($order->id)->update(['needs_attention' => false, 'attention_note' => null]);
        }
    });
    expect(fn () => app(ClearAttention::class)->handle($order, 'Checked', User::factory()->sales()->create()))
        ->toThrow(ValidationException::class, 'This order is not flagged.');
    expect($order->events()->count())->toBe(0);
});
