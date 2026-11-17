<?php

namespace App\Actions\Orders;

use App\Domain\Order\QuoteLinesComparison;
use App\Domain\Order\QuoteValidity;
use App\Domain\Order\StateMachine;
use App\Enums\AcceptedVia;
use App\Enums\OrderEventType;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Enums\StockReason;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Lock order: customer row (shared) -> order row -> stock_levels ascending variant_id. */
final class AcceptQuoteByStaff
{
    public function __construct(
        private ValidateQuoteCustomer $customers,
        private QuoteContent $content,
        private ReduceOrderStock $stock,
        private RecordOrderEvent $events,
    ) {}

    public function handle(Order $quote, User $by, ?string $note = null, ?string $stockOverrideReason = null): Order
    {
        $quote = Order::query()->findOrFail($quote->id);
        $this->validate($quote);
        $this->customers->handle($quote->customer);
        $note = trim($note ?? '');
        $stockOverrideReason = trim($stockOverrideReason ?? '');
        $stockOverrideReason = $stockOverrideReason === '' ? null : $stockOverrideReason;
        if (mb_strlen($note) > 255) {
            throw ValidationException::withMessages(['note' => 'The acceptance note must be at most 255 characters.']);
        }
        if (mb_strlen($stockOverrideReason ?? '') > 255) {
            throw ValidationException::withMessages(['stock_override_reason' => 'The stock override reason must be at most 255 characters.']);
        }
        Gate::forUser($by)->authorize('accept', $quote);
        if ($stockOverrideReason !== null) {
            Gate::forUser($by)->authorize('overrideStock', Order::class);
        }

        return DB::transaction(function () use ($quote, $by, $note, $stockOverrideReason): Order {
            $customer = $this->customers->handle(Customer::query()->whereKey($quote->customer_id)->sharedLock()->first());
            $quote = Order::query()->lockForUpdate()->findOrFail($quote->id);
            $this->validate($quote);
            if ($quote->customer_id !== $customer->id) {
                throw ValidationException::withMessages(['customer_id' => 'The quote customer has changed. Reload the quote and try again.']);
            }
            $effects = StateMachine::assert(OrderStatus::Quoted, OrderStatus::Confirmed);
            $overrides = $effects->reduceStock ? $this->stock->handle($quote, StockReason::QuoteAccepted, $by, $stockOverrideReason) : [];
            $quote->update([
                'status' => OrderStatus::Confirmed, 'accepted_at' => now(), 'accepted_via' => AcceptedVia::Staff, 'confirmed_at' => now(),
            ]);
            $this->events->handle($quote, OrderEventType::Status, from: OrderStatus::Quoted, to: OrderStatus::Confirmed, note: $note === '' ? 'Accepted by staff' : $note, by: $by);
            if ($overrides !== []) {
                $this->events->handle($quote, OrderEventType::Note, note: mb_substr('Stock override: '.$stockOverrideReason.' — '.implode(', ', $overrides), 0, 255), by: $by);
            }

            return $quote->refresh()->load('lines');
        });
    }

    private function validate(Order $quote): void
    {
        if ($quote->status !== OrderStatus::Quoted) {
            throw ValidationException::withMessages(['status' => 'This quote is no longer open.']);
        }
        if (QuoteValidity::isExpired(now()->getTimestamp(), $quote->valid_until?->getTimestamp())) {
            $date = $quote->valid_until?->copy()->setTimezone('Asia/Colombo')->format('d M Y');
            throw ValidationException::withMessages(['valid_until' => 'This quote expired on '.$date.'. Change the validity date and send it again.']);
        }
        if ($quote->quote_revision < 1) {
            throw ValidationException::withMessages(['quote_revision' => 'Send the quote before marking it accepted.']);
        }
        $productLines = $quote->lines()->with('variant')->where('kind', OrderLineKind::Product)->get();
        if ($productLines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'At least one product line is required before accepting the quote.']);
        }
        foreach ($productLines as $line) {
            if ($line->variant_id === null || $line->variant === null) {
                throw ValidationException::withMessages(['lines' => 'The variant for '.$line->description.' no longer exists.']);
            }
        }
        $snapshot = $quote->quoteSnapshots()->orderByDesc('revision')->first();
        if ($snapshot === null) {
            throw ValidationException::withMessages(['quote_revision' => 'The quote has changed since revision '.$quote->quote_revision.' was sent. Send it again first.']);
        }
        if ($snapshot->revision !== $quote->quote_revision || ! QuoteLinesComparison::same($this->content->handle($quote), $this->content->snapshot($snapshot))) {
            throw ValidationException::withMessages(['quote_revision' => 'The quote has changed since revision '.$snapshot->revision.' was sent. Send it again first.']);
        }
    }
}
