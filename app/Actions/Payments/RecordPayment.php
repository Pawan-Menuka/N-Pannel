<?php

namespace App\Actions\Payments;

use App\Actions\Documents\NextDocumentNumber;
use App\Actions\Orders\RecalculateOrderPayments;
use App\Actions\Orders\RecordOrderEvent;
use App\Domain\Money\Lkr;
use App\Enums\DocumentKind;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Lock the order row before any stock rows. IS-8 through IS-10 must follow this order.
 * Multi-row stock operations must then lock stock_levels in ascending variant_id order.
 */
final class RecordPayment
{
    public function __construct(private NextDocumentNumber $numbers, private RecalculateOrderPayments $payments, private RecordOrderEvent $events) {}

    public function handle(Order $order, PaymentMethod $method, int $amountCents, ?string $reference, ?string $note, User $by): Payment
    {
        Gate::forUser($by)->authorize('create', Payment::class);
        if ($method === PaymentMethod::Payhere) {
            throw ValidationException::withMessages(['method' => 'PayHere payments are recorded by the gateway.']);
        }
        $reference = trim($reference ?? '');
        $note = trim($note ?? '');
        if (in_array($method, [PaymentMethod::BankTransfer, PaymentMethod::Cheque], true) && $reference === '') {
            throw ValidationException::withMessages(['reference' => 'A reference is required for bank transfers and cheques.']);
        }
        if (mb_strlen($reference) > 255) {
            throw ValidationException::withMessages(['reference' => 'The reference must be at most 255 characters.']);
        }
        if (mb_strlen($note) > 255) {
            throw ValidationException::withMessages(['note' => 'The note must be at most 255 characters.']);
        }

        return DB::transaction(function () use ($order, $method, $amountCents, $reference, $note, $by): Payment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($amountCents < 1 || $amountCents > 10000000000) {
                throw ValidationException::withMessages(['amount_cents' => 'The payment must be between Rs 0.01 and Rs 100,000,000.00.']);
            }
            $this->payments->handle($locked);
            $late = in_array($locked->status, [OrderStatus::Cancelled, OrderStatus::Expired], true);
            if (! $late && $amountCents > $locked->balanceCents()) {
                throw ValidationException::withMessages(['amount_cents' => 'This payment is more than the balance of '.Lkr::formatWithCurrency($locked->balanceCents()).'.']);
            }
            $receipt = $this->numbers->handle(DocumentKind::Receipt);
            $payment = $locked->payments()->create([
                'kind' => PaymentKind::Payment,
                'method' => $method,
                'status' => PaymentStatus::Confirmed,
                'amount_cents' => $amountCents,
                'reference' => $reference === '' ? null : $reference,
                'note' => $note === '' ? null : $note,
                'receipt_number' => $receipt,
                'created_by_id' => $by->id,
                'confirmed_by_id' => $by->id,
                'confirmed_at' => now(),
            ]);
            $this->payments->handle($locked);
            if ($late) {
                $attention = 'Payment of '.Lkr::formatWithCurrency($amountCents).' received after the order was '.$locked->status->value.'.';
                $existing = $locked->attention_note;
                $combined = $existing === null || $existing === '' ? $attention : $existing.' '.$attention;
                $locked->update([
                    'needs_attention' => true,
                    'attention_note' => mb_strlen($combined) <= 255 ? $combined : $existing,
                ]);
                $this->events->handle($locked, OrderEventType::Attention, note: $attention, by: $by);
            }
            $this->events->handle($locked, OrderEventType::Payment, note: 'Payment '.Lkr::formatWithCurrency($amountCents).' by '.$method->getLabel().', receipt '.$receipt, by: $by);

            return $payment;
        });
    }
}
