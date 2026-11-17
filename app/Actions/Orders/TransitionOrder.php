<?php

namespace App\Actions\Orders;

use App\Actions\Stock\ReleaseStock;
use App\Domain\Money\Lkr;
use App\Domain\Order\InvalidTransition;
use App\Domain\Order\StateMachine;
use App\Domain\Order\TransitionEffects;
use App\Domain\Stock\StockLimitExceeded;
use App\Enums\FulfilmentMethod;
use App\Enums\LateCancelReason;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\StockReason;
use App\Models\Location;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Lock order: order -> stock_levels ascending variant_id. No customer or sequence is changed. */
final class TransitionOrder
{
    public function __construct(
        private ReleaseStock $stock,
        private RecalculateOrderPayments $payments,
        private RecordOrderEvent $events,
        private AppendAttentionNote $attention,
    ) {}

    /** @return list<OrderStatus> */
    public static function targetsFor(Order $order): array
    {
        $supported = match ($order->status) {
            OrderStatus::Quoted => [OrderStatus::Cancelled],
            OrderStatus::Confirmed => [OrderStatus::Processing, OrderStatus::Cancelled],
            OrderStatus::Processing => [OrderStatus::ReadyForCollection, OrderStatus::Dispatched, OrderStatus::Cancelled],
            OrderStatus::ReadyForCollection, OrderStatus::Dispatched => [OrderStatus::Completed, OrderStatus::Cancelled],
            default => [],
        };

        return array_values(array_filter(StateMachine::allowedFrom($order->status), fn (OrderStatus $target): bool => in_array($target, $supported, true)));
    }

    public function handle(Order $order, OrderStatus $to, User $by, ?string $note = null, ?string $cancelReason = null, ?string $completeOverrideReason = null): Order
    {
        $order = Order::query()->findOrFail($order->id);
        $note = trim($note ?? '');
        $cancelReason = trim($cancelReason ?? '');
        $completeOverrideReason = trim($completeOverrideReason ?? '');
        $effects = $this->validate($order, $to, $cancelReason);
        foreach (['note' => $note, 'cancel_reason' => $cancelReason, 'complete_override_reason' => $completeOverrideReason] as $field => $value) {
            if (mb_strlen($value) > 255) {
                throw ValidationException::withMessages([$field => 'The '.str_replace('_', ' ', $field).' must be at most 255 characters.']);
            }
        }
        $order->fill($this->payments->totals($order));
        $this->validateBalance($order, $effects, $completeOverrideReason);
        Gate::forUser($by)->authorize('transition', $order);
        if ($to === OrderStatus::Completed && $completeOverrideReason !== '') {
            Gate::forUser($by)->authorize('completeOverride', $order);
        }

        return DB::transaction(function () use ($order, $to, $by, $note, $cancelReason, $completeOverrideReason): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $effects = $this->validate($locked, $to, $cancelReason);
            $this->payments->handle($locked);
            $this->validateBalance($locked, $effects, $completeOverrideReason);
            $from = $locked->status;
            $reasonLabel = LateCancelReason::tryFrom($cancelReason)?->getLabel() ?? $cancelReason;
            $owed = $locked->balanceCents();
            if ($effects->releaseStock) {
                $this->release($locked, $by);
            }
            $locked->status = $to;
            if ($to === OrderStatus::Cancelled) {
                $locked->cancelled_at = now();
                $locked->cancel_reason = $cancelReason;
            }
            if ($to === OrderStatus::Completed) {
                $locked->completed_at = now();
            }
            if ($to === OrderStatus::ReadyForCollection) {
                $locked->fulfilment = FulfilmentMethod::Collection;
            }
            if ($to === OrderStatus::Dispatched) {
                $locked->fulfilment = FulfilmentMethod::Delivery;
            }
            $locked->save();
            $this->events->handle($locked, OrderEventType::Status, from: $from, to: $to, note: $note !== '' ? $note : ($to === OrderStatus::Cancelled ? $reasonLabel : null), by: $by);
            if ($effects->flagNeedsAttention) {
                $this->attention->handle($locked, 'Cancelled after '.$from->getLabel().': '.$reasonLabel.'. Check what came back and adjust stock.', $by);
            }
            if ($to === OrderStatus::Cancelled && $locked->paid_cents > 0) {
                $this->attention->handle($locked, Lkr::formatWithCurrency($locked->paid_cents).' was paid on this cancelled order — record a refund.', $by);
            }
            if ($effects->requiresSettledBalance && $owed > 0) {
                $this->events->handle($locked, OrderEventType::Note, note: mb_substr('Completed with '.Lkr::formatWithCurrency($owed).' owed: '.$completeOverrideReason, 0, 255), by: $by);
            }
            $this->payments->handle($locked);

            return $locked->refresh();
        });
    }

    private function validate(Order $order, OrderStatus $to, string $cancelReason): TransitionEffects
    {
        if ($to === OrderStatus::Cancelled && $order->salesReturns()->exists()) {
            throw ValidationException::withMessages(['to' => 'This order has returns and cannot be cancelled.']);
        }
        if (! in_array($to, self::targetsFor($order), true)) {
            $message = match (true) {
                $order->status === OrderStatus::Placed => 'Placed orders belong to online checkout and cannot be moved here.',
                $order->status === OrderStatus::Quoted && $to === OrderStatus::Confirmed => 'Use Mark accepted to accept a quote.',
                $to === OrderStatus::Expired => 'Only the scheduled sweep can expire an order.',
                default => 'An order cannot move from '.$order->status->getLabel().' to '.$to->getLabel().' here.',
            };
            throw ValidationException::withMessages(['to' => $message]);
        }
        if ($to === OrderStatus::Cancelled && ($invoice = $order->activeInvoice()) !== null) {
            throw ValidationException::withMessages(['to' => 'Void invoice '.$invoice->number.' before cancelling this order.']);
        }
        try {
            return StateMachine::assert($order->status, $to, $cancelReason);
        } catch (InvalidTransition $exception) {
            throw ValidationException::withMessages(['to' => $exception->getMessage()]);
        }
    }

    private function validateBalance(Order $order, TransitionEffects $effects, string $overrideReason): void
    {
        if ($effects->requiresSettledBalance && $order->balanceCents() > 0 && $overrideReason === '') {
            throw ValidationException::withMessages(['to' => Lkr::formatWithCurrency($order->balanceCents()).' is still owed. Record the payment first.']);
        }
    }

    private function release(Order $order, User $by): void
    {
        $movements = StockMovement::query()->where('source_type', $order->getMorphClass())->where('source_id', $order->id)
            ->select('variant_id', 'location_id')->selectRaw('SUM(delta) AS net_delta')
            ->groupBy('variant_id', 'location_id')->havingRaw('SUM(delta) < 0')
            ->orderBy('variant_id')->orderBy('location_id')->get();
        try {
            foreach ($movements as $movement) {
                $variant = Variant::query()->findOrFail($movement->variant_id);
                $location = Location::query()->findOrFail($movement->location_id);
                /** Historical deductions must be returned even if tracking was subsequently disabled. */
                $variant->track_stock = true;
                $remaining = -(int) $movement->getAttribute('net_delta');
                while ($remaining > 0) {
                    $qty = min($remaining, 1000000);
                    $this->stock->handle($variant, $qty, StockReason::OrderCancelled, $order, $by, location: $location);
                    $remaining -= $qty;
                }
            }
        } catch (StockLimitExceeded $exception) {
            throw ValidationException::withMessages(['stock' => $exception->getMessage()]);
        }
    }
}
