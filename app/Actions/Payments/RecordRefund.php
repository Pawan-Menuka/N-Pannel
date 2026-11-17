<?php

namespace App\Actions\Payments;

use App\Actions\Orders\RecalculateOrderPayments;
use App\Actions\Orders\RecordOrderEvent;
use App\Domain\Money\Lkr;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SalesReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Lock the order row before any stock rows. IS-8 through IS-12 must follow this order.
 * Multi-row stock operations must then lock stock_levels in ascending variant_id order.
 * Returns reduce the effective total before their linked refund is recorded.
 */
final class RecordRefund
{
    public function __construct(private RecalculateOrderPayments $payments, private RecordOrderEvent $events) {}

    public function handle(Order $order, PaymentMethod $method, int $amountCents, string $note, User $by, ?SalesReturn $salesReturn = null): Payment
    {
        Gate::forUser($by)->authorize('refund', Payment::class);
        if ($method === PaymentMethod::Payhere) {
            throw ValidationException::withMessages(['method' => 'PayHere refunds cannot be recorded by staff.']);
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 255) {
            throw ValidationException::withMessages(['note' => 'A refund note of at most 255 characters is required.']);
        }

        return DB::transaction(function () use ($order, $method, $amountCents, $note, $by, $salesReturn): Payment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($salesReturn !== null && (! $salesReturn->exists || SalesReturn::query()->whereKey($salesReturn->id)->where('order_id', $locked->id)->sharedLock()->first() === null)) {
                throw ValidationException::withMessages(['sales_return' => 'The sales return must belong to this order.']);
            }
            $this->payments->handle($locked);
            if ($salesReturn !== null) {
                $maximum = max(0, $locked->paid_cents - $locked->effectiveTotalCents());
                if ($amountCents < 1 || $amountCents > $maximum) {
                    throw ValidationException::withMessages(['amount_cents' => 'Up to '.Lkr::formatWithCurrency($maximum).' can be refunded.']);
                }
            }
            if ($salesReturn === null && ! in_array($locked->status, [OrderStatus::Cancelled, OrderStatus::Expired], true)) {
                $overpayment = max(0, $locked->paid_cents - $locked->effectiveTotalCents());
                if ($amountCents < 1 || $amountCents > $overpayment) {
                    $message = $overpayment === 0
                        ? 'Nothing has been overpaid on this order. Cancel the order or record a return first.'
                        : 'Only an overpayment of '.Lkr::formatWithCurrency($overpayment).' can be refunded on an active order. Cancel the order or record a return first.';
                    throw ValidationException::withMessages(['amount_cents' => $message]);
                }
            }
            if ($amountCents < 1 || $amountCents > $locked->paid_cents) {
                throw ValidationException::withMessages(['amount_cents' => 'You can refund at most '.Lkr::formatWithCurrency(max(0, $locked->paid_cents)).'.']);
            }
            $refund = $locked->payments()->create([
                'kind' => PaymentKind::Refund,
                'method' => $method,
                'status' => PaymentStatus::Confirmed,
                'amount_cents' => $amountCents,
                'note' => $note,
                'receipt_number' => null,
                'created_by_id' => $by->id,
                'confirmed_by_id' => $by->id,
                'confirmed_at' => now(),
                'sales_return_id' => $salesReturn?->id,
            ]);
            $this->payments->handle($locked);
            /** The full note lives on the refund; the event summary column holds 255 characters. */
            $summary = mb_substr('Refund '.Lkr::formatWithCurrency($amountCents).' by '.$method->getLabel().': '.$note, 0, 255);
            $this->events->handle($locked, OrderEventType::Payment, note: $summary, by: $by);

            return $refund;
        });
    }
}
