<?php

namespace App\Actions\Orders;

use App\Domain\Order\AmountTooLarge;
use App\Domain\Order\InvalidOrderTotals;
use App\Domain\Order\OrderTotals;
use App\Domain\Order\Totals;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** @phpstan-import-type PreparedOrderLine from PrepareOrderLines */
final class SaveQuoteLines
{
    public function __construct(
        private PrepareOrderLines $prepareLines,
        private ValidateQuoteCustomer $customers,
        private RecalculateOrderPayments $payments,
    ) {}

    /** @param list<OrderLineInput> $lines */
    public function handle(Order $quote, array $lines, ?int $requiredAdvanceCents, ?string $exclusions, CarbonImmutable $validUntilDate, User $by): Order
    {
        $quote = Order::query()->findOrFail($quote->id);
        $this->assertOpen($quote);
        $exclusions = trim($exclusions ?? '');
        $exclusions = $exclusions === '' ? null : $exclusions;
        if (mb_strlen($exclusions ?? '') > 2000) {
            throw ValidationException::withMessages(['exclusions' => 'Exclusions must be at most 2000 characters.']);
        }
        $this->assertDate($validUntilDate);
        $customer = $this->customers->handle($quote->customer);
        $this->totals($this->prepareLines->handle($customer, $lines, allowEmpty: true), $requiredAdvanceCents);
        Gate::forUser($by)->authorize('update', $quote);

        return DB::transaction(function () use ($quote, $lines, $requiredAdvanceCents, $exclusions, $validUntilDate): Order {
            $customer = $this->customers->handle(Customer::query()->whereKey($quote->customer_id)->sharedLock()->first());
            $quote = Order::query()->lockForUpdate()->findOrFail($quote->id);
            $this->assertOpen($quote);
            if ($quote->customer_id !== $customer->id) {
                throw ValidationException::withMessages(['customer_id' => 'The quote customer has changed. Reload the quote and try again.']);
            }
            $this->assertDate($validUntilDate);
            $prepared = $this->prepareLines->handle($customer, $lines, allowEmpty: true);
            $totals = $this->totals($prepared, $requiredAdvanceCents);
            $quote->lines()->delete();
            foreach ($prepared as $index => $line) {
                $variant = $line['variant'];
                $quote->lines()->create([
                    'kind' => $line['kind'], 'variant_id' => $variant?->id, 'sku' => $variant?->sku,
                    'description' => $line['description'], 'sale_unit' => $variant?->sale_unit,
                    'qty' => $line['qty'], 'unit_price_cents' => $line['unitPriceCents'],
                    'unit_cost_cents' => $variant?->last_cost_cents, 'line_total_cents' => $line['lineTotalCents'], 'sort_order' => $index,
                ]);
            }
            $quote->update([
                'subtotal_cents' => $totals->subtotalCents, 'delivery_cents' => $totals->deliveryCents,
                'discount_cents' => $totals->discountCents, 'total_cents' => $totals->totalCents,
                'required_advance_cents' => $requiredAdvanceCents, 'exclusions' => $exclusions,
                'valid_until' => $validUntilDate->setTimezone('Asia/Colombo')->setTime(23, 59, 59)->setTimezone(config('app.timezone')),
            ]);
            $this->payments->handle($quote);

            return $quote->refresh()->load('lines');
        });
    }

    /** @param list<PreparedOrderLine> $prepared */
    private function totals(array $prepared, ?int $requiredAdvanceCents): OrderTotals
    {
        $lines = [];
        foreach ($prepared as $line) {
            $lines[] = ['kind' => $line['kind'], 'qty' => $line['qty'], 'unitPriceCents' => $line['unitPriceCents']];
        }
        try {
            return Totals::calculate($lines, $requiredAdvanceCents);
        } catch (InvalidOrderTotals|AmountTooLarge $exception) {
            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }
    }

    private function assertOpen(Order $quote): void
    {
        if ($quote->status !== OrderStatus::Quoted) {
            throw ValidationException::withMessages(['status' => 'Only an open quote can be changed.']);
        }
    }

    private function assertDate(CarbonImmutable $date): void
    {
        if ($date->setTimezone('Asia/Colombo')->startOfDay()->lt(CarbonImmutable::now('Asia/Colombo')->startOfDay())) {
            throw ValidationException::withMessages(['valid_until' => 'The validity date must be today or later.']);
        }
    }
}
