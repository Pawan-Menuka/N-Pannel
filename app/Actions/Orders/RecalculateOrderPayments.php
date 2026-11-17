<?php

namespace App\Actions\Orders;

use App\Domain\Order\PaymentStates;
use App\Domain\Order\Totals;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use LogicException;

/** The caller must hold the order row lock before recalculating its payments. */
final class RecalculateOrderPayments
{
    public function handle(Order $order): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Recalculating order payments requires a database transaction.');
        }
        $received = 0;
        $refunded = 0;
        foreach ($order->payments()->where('status', PaymentStatus::Confirmed)->get() as $payment) {
            if ($payment->kind === PaymentKind::Refund) {
                $refunded = Totals::add($refunded, $payment->amount_cents);
            } else {
                $received = Totals::add($received, $payment->amount_cents);
            }
        }
        $net = $received - $refunded;
        $order->update([
            'paid_cents' => $net,
            'refunded_cents' => $refunded,
            'payment_state' => PaymentStates::derive($order->total_cents, $net, $refunded),
        ]);
    }
}
