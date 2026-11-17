<?php

namespace App\Domain\Order;

use App\Enums\OrderStatus;

final class StateMachine
{
    public static function can(?OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to, self::allowedFrom($from), true);
    }

    /** @return list<OrderStatus> */
    public static function allowedFrom(?OrderStatus $from): array
    {
        return match ($from) {
            null => [OrderStatus::Quoted, OrderStatus::Confirmed, OrderStatus::Placed],
            OrderStatus::Quoted => [OrderStatus::Confirmed, OrderStatus::Cancelled, OrderStatus::Expired, OrderStatus::Placed],
            OrderStatus::Placed => [OrderStatus::Confirmed, OrderStatus::Expired, OrderStatus::Cancelled],
            OrderStatus::Confirmed => [OrderStatus::Processing, OrderStatus::Cancelled],
            OrderStatus::Processing => [OrderStatus::ReadyForCollection, OrderStatus::Dispatched, OrderStatus::Cancelled],
            OrderStatus::ReadyForCollection, OrderStatus::Dispatched => [OrderStatus::Completed, OrderStatus::Cancelled],
            OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Expired => [],
        };
    }

    public static function assert(?OrderStatus $from, OrderStatus $to, ?string $cancelReason = null): TransitionEffects
    {
        if (! self::can($from, $to)) {
            throw new InvalidTransition('An order cannot move from '.($from?->getLabel() ?? '(none)').' to '.$to->getLabel().'.');
        }
        $cancel = $to === OrderStatus::Cancelled;
        $lateCancel = $cancel && in_array($from, [OrderStatus::ReadyForCollection, OrderStatus::Dispatched], true);
        $reasons = $lateCancel ? ['delivery_refused', 'not_collected', 'damaged_in_transit'] : null;
        if ($cancel && trim($cancelReason ?? '') === '') {
            throw new InvalidTransition('A cancellation reason is required.');
        }
        if ($reasons !== null && ! in_array($cancelReason, $reasons, true)) {
            throw new InvalidTransition('The cancellation reason must be delivery_refused, not_collected or damaged_in_transit.');
        }

        return new TransitionEffects(
            reduceStock: $to === OrderStatus::Confirmed && in_array($from, [null, OrderStatus::Quoted], true),
            reserveStock: $to === OrderStatus::Placed,
            releaseStock: ($cancel && in_array($from, [OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Processing], true)) || ($from === OrderStatus::Placed && $to === OrderStatus::Expired),
            flagNeedsAttention: $lateCancel,
            requiresSettledBalance: $to === OrderStatus::Completed,
            requiresCancelReason: $cancel,
            allowedCancelReasons: $reasons,
            requiresNothingPaid: $from === OrderStatus::Placed && $to === OrderStatus::Expired,
        );
    }
}
