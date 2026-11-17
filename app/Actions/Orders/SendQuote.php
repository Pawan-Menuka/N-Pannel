<?php

namespace App\Actions\Orders;

use App\Domain\Money\Lkr;
use App\Domain\Order\QuoteMessage;
use App\Domain\Order\QuoteValidity;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Settings\BusinessSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Lock order: customer row (shared) -> order row. */
final class SendQuote
{
    public function __construct(
        private QuoteContent $content,
        private RotateOrderToken $tokens,
        private RecordOrderEvent $events,
        private BusinessSettings $business,
        private ValidateQuoteCustomer $customers,
    ) {}

    public function handle(Order $quote, User $by): QuoteLink
    {
        $quote = Order::query()->findOrFail($quote->id);
        $this->validate($quote);
        $this->customers->handle($quote->customer);
        Gate::forUser($by)->authorize('send', $quote);

        return DB::transaction(function () use ($quote, $by): QuoteLink {
            $customer = $this->customers->handle(Customer::query()->whereKey($quote->customer_id)->sharedLock()->first());
            $quote = Order::query()->lockForUpdate()->findOrFail($quote->id);
            $this->validate($quote);
            if ($quote->customer_id !== $customer->id) {
                throw ValidationException::withMessages(['customer_id' => 'The quote customer has changed. Reload the quote and try again.']);
            }
            $revision = $quote->quote_revision + 1;
            $content = $this->content->handle($quote);
            $quote->quoteSnapshots()->create([
                'revision' => $revision, 'lines' => $content['lines'], 'total_cents' => $content['total_cents'],
                'required_advance_cents' => $content['required_advance_cents'], 'exclusions' => $content['exclusions'],
                'valid_until' => $quote->valid_until, 'created_by_id' => $by->id,
            ]);
            $quote->update(['quote_revision' => $revision]);
            $plain = $this->tokens->handle($quote);
            $this->events->handle($quote, OrderEventType::Note, note: 'Quote sent (revision '.$revision.')', by: $by);
            $url = route('quotes.show', ['reference' => $quote->reference, 'token' => $plain]);
            $text = QuoteMessage::whatsApp($quote->customer_name, $this->business->name, $quote->reference, $revision, Lkr::formatWithCurrency($quote->total_cents), $quote->valid_until?->copy()->setTimezone('Asia/Colombo')->format('d M Y') ?? 'No expiry', $url);

            return new QuoteLink($url, $revision, $text, QuoteMessage::whatsAppUrl($quote->phone_e164, $text));
        });
    }

    private function validate(Order $quote): void
    {
        if ($quote->status !== OrderStatus::Quoted) {
            throw ValidationException::withMessages(['status' => 'Only an open quote can be sent.']);
        }
        if (! $quote->lines()->exists()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line before sending the quote.']);
        }
        if (QuoteValidity::isExpired(now()->getTimestamp(), $quote->valid_until?->getTimestamp())) {
            throw ValidationException::withMessages(['valid_until' => "This quote's validity date has passed. Change it before sending."]);
        }
    }
}
