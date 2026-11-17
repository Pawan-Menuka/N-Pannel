<?php

use App\Domain\Order\InvalidTransition;
use App\Domain\Order\StateMachine;
use App\Enums\OrderStatus;

dataset('order transition pairs', function () {
    $allowed = [
        'none' => ['quoted', 'confirmed', 'placed'],
        'quoted' => ['confirmed', 'cancelled', 'expired', 'placed'],
        'placed' => ['confirmed', 'expired', 'cancelled'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['ready_for_collection', 'dispatched', 'cancelled'],
        'ready_for_collection' => ['completed', 'cancelled'],
        'dispatched' => ['completed', 'cancelled'],
    ];
    foreach ([null, ...OrderStatus::cases()] as $from) {
        foreach (OrderStatus::cases() as $to) {
            $key = $from?->value ?? 'none';
            yield $key.' to '.$to->value => [$from, $to, in_array($to->value, $allowed[$key] ?? [], true)];
        }
    }
});

it('accepts exactly the complete transition table with the required effects', function (?OrderStatus $from, OrderStatus $to, bool $allowed) {
    expect(StateMachine::can($from, $to))->toBe($allowed);
    expect(in_array($to, StateMachine::allowedFrom($from), true))->toBe($allowed);
    if (! $allowed) {
        expect(fn () => StateMachine::assert($from, $to, 'not_collected'))->toThrow(InvalidTransition::class);

        return;
    }

    $effects = StateMachine::assert($from, $to, 'not_collected');
    $late = in_array($from, [OrderStatus::ReadyForCollection, OrderStatus::Dispatched], true) && $to === OrderStatus::Cancelled;
    expect($effects->reduceStock)->toBe($to === OrderStatus::Confirmed && in_array($from, [null, OrderStatus::Quoted], true));
    expect($effects->reserveStock)->toBe($to === OrderStatus::Placed);
    expect($effects->releaseStock)->toBe(($to === OrderStatus::Cancelled && in_array($from, [OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Processing], true)) || ($from === OrderStatus::Placed && $to === OrderStatus::Expired));
    expect($effects->flagNeedsAttention)->toBe($late);
    expect($effects->requiresSettledBalance)->toBe($to === OrderStatus::Completed);
    expect($effects->requiresNothingPaid)->toBe($from === OrderStatus::Placed && $to === OrderStatus::Expired);
    expect($effects->requiresCancelReason)->toBe($to === OrderStatus::Cancelled);
    expect($effects->allowedCancelReasons)->toBe($late ? ['delivery_refused', 'not_collected', 'damaged_in_transit'] : null);
})->with('order transition pairs');

it('requires a nonblank cancellation reason at every cancellable stage', function (OrderStatus $from, ?string $reason) {
    expect(fn () => StateMachine::assert($from, OrderStatus::Cancelled, $reason))->toThrow(InvalidTransition::class, 'reason');
})->with([OrderStatus::Quoted, OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched])->with([null, '', '  ']);

it('restricts late cancellations to the three reason codes', function (OrderStatus $from, string $reason) {
    expect(StateMachine::assert($from, OrderStatus::Cancelled, $reason)->flagNeedsAttention)->toBeTrue();
})->with([OrderStatus::ReadyForCollection, OrderStatus::Dispatched])->with(['delivery_refused', 'not_collected', 'damaged_in_transit']);

it('refuses free text for late cancellations', function (OrderStatus $from) {
    expect(fn () => StateMachine::assert($from, OrderStatus::Cancelled, 'Customer changed their mind'))->toThrow(InvalidTransition::class);
})->with([OrderStatus::ReadyForCollection, OrderStatus::Dispatched]);

it('accepts free text for early cancellations', function (OrderStatus $from) {
    expect(StateMachine::assert($from, OrderStatus::Cancelled, ' Customer changed their mind ')->allowedCancelReasons)->toBeNull();
})->with([OrderStatus::Quoted, OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Processing]);

it('names the rejected transition clearly', function () {
    expect(fn () => StateMachine::assert(OrderStatus::Quoted, OrderStatus::Completed))->toThrow(InvalidTransition::class, 'An order cannot move from Quoted to Completed.');
});
