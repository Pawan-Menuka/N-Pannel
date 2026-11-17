<?php

namespace App\Actions\Orders;

use App\Actions\Payments\RecordPayment;
use App\Domain\Order\AmountTooLarge;
use App\Domain\Order\InvalidOrderTotals;
use App\Domain\Order\Totals;
use App\Domain\Stock\StockLimitExceeded;
use App\Enums\OrderEventType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\StockReason;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Lock order: customer row (shared) -> order row -> document sequence -> stock_levels ascending variant_id.
 * Reference inserts use savepoints so a PostgreSQL collision cannot abort the sale transaction.
 */
final class CreateCounterSale
{
    public function __construct(
        private InsertOrderWithReference $insertOrder,
        private PrepareOrderLines $prepareLines,
        private RecalculateOrderPayments $payments,
        private RecordOrderEvent $events,
        private RecordPayment $recordPayment,
        private ReduceOrderStock $stock,
    ) {}

    /** @param list<OrderLineInput> $lines */
    public function handle(Customer $customer, array $lines, User $by, ?CounterSalePayment $payment = null, ?string $stockOverrideReason = null): Order
    {
        $customer = $this->validateCustomer($customer->fresh());
        Gate::forUser($by)->authorize('create', Order::class);
        $stockOverrideReason = trim($stockOverrideReason ?? '');
        $stockOverrideReason = $stockOverrideReason === '' ? null : $stockOverrideReason;
        if (mb_strlen($stockOverrideReason ?? '') > 255) {
            throw ValidationException::withMessages(['stock_override_reason' => 'The stock override reason must be at most 255 characters.']);
        }
        if ($stockOverrideReason !== null) {
            Gate::forUser($by)->authorize('overrideStock', Order::class);
        }

        try {
            return DB::transaction(function () use ($customer, $lines, $by, $payment, $stockOverrideReason): Order {
                $customer = $this->validateCustomer(Customer::query()->whereKey($customer->id)->sharedLock()->first());
                $prepared = $this->prepareLines->handle($customer, $lines);
                $totalLines = [];
                foreach ($prepared as $line) {
                    $totalLines[] = ['kind' => $line['kind'], 'qty' => $line['qty'], 'unitPriceCents' => $line['unitPriceCents']];
                }
                try {
                    $totals = Totals::calculate($totalLines);
                } catch (InvalidOrderTotals|AmountTooLarge $exception) {
                    throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
                }
                if ($customer->is_walk_in && $totals->totalCents !== 0 && $payment?->amountCents !== $totals->totalCents) {
                    throw ValidationException::withMessages(['payment.amount' => 'A walk-in sale must be paid in full.']);
                }
                if ($payment !== null && ($payment->amountCents < 1 || $payment->amountCents > $totals->totalCents)) {
                    throw ValidationException::withMessages(['payment.amount' => 'The payment must be between Rs 0.01 and the order total.']);
                }
                $location = Location::default();
                $order = $this->insertOrder->handle('O', [
                    'source' => OrderSource::Counter, 'status' => OrderStatus::Confirmed,
                    'confirmed_at' => now(), 'payment_state' => PaymentState::Unpaid,
                    'location_id' => $location->id, 'price_tier' => $customer->price_tier,
                    'customer_id' => $customer->id, 'customer_name' => $customer->is_walk_in ? 'Walk-in customer' : $customer->name,
                    'phone_e164' => $customer->phone_e164, 'email' => $customer->email,
                    'created_by_id' => $by->id, 'locale' => config('app.locale', 'en'),
                    'subtotal_cents' => $totals->subtotalCents, 'delivery_cents' => $totals->deliveryCents,
                    'discount_cents' => $totals->discountCents, 'total_cents' => $totals->totalCents,
                ]);
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                foreach ($prepared as $index => $line) {
                    $variant = $line['variant'];
                    $order->lines()->create([
                        'kind' => $line['kind'], 'variant_id' => $variant?->id, 'sku' => $variant?->sku,
                        'description' => $line['description'], 'sale_unit' => $variant?->sale_unit,
                        'qty' => $line['qty'], 'unit_price_cents' => $line['unitPriceCents'],
                        'unit_cost_cents' => $variant?->last_cost_cents, 'line_total_cents' => $line['lineTotalCents'], 'sort_order' => $index,
                    ]);
                }
                $this->payments->handle($order);
                $this->events->handle($order, OrderEventType::Status, to: OrderStatus::Confirmed, note: 'Counter sale', by: $by);
                if ($payment !== null) {
                    try {
                        $this->recordPayment->handle($order, $payment->method, $payment->amountCents, $payment->reference, $payment->note, $by);
                    } catch (ValidationException $exception) {
                        $errors = [];
                        foreach ($exception->errors() as $key => $messages) {
                            $errors['payment.'.($key === 'amount_cents' ? 'amount' : $key)] = $messages;
                        }
                        throw ValidationException::withMessages($errors);
                    }
                }
                $overrides = $this->stock->handle($order, StockReason::CounterSale, $by, $stockOverrideReason);
                if ($overrides !== []) {
                    $this->events->handle($order, OrderEventType::Note, note: mb_substr('Stock override: '.$stockOverrideReason.' — '.implode(', ', $overrides), 0, 255), by: $by);
                }

                return $order->refresh()->load('lines', 'payments');
            });
        } catch (StockLimitExceeded $exception) {
            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }
    }

    private function validateCustomer(?Customer $customer): Customer
    {
        if ($customer === null) {
            throw ValidationException::withMessages(['customer_id' => 'This customer no longer exists.']);
        }
        if ($customer->merged_into_id !== null) {
            throw ValidationException::withMessages(['customer_id' => 'This customer has been merged. Pick the surviving customer.']);
        }
        if ($customer->anonymised_at !== null) {
            throw ValidationException::withMessages(['customer_id' => 'This customer has been anonymised. Pick another customer.']);
        }

        return $customer;
    }
}
