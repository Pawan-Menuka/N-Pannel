<?php

namespace App\Actions\Orders;

use App\Domain\Money\Lkr;
use App\Domain\Order\QuoteLinesComparison;
use App\Domain\Order\QuoteMessage;
use App\Domain\Order\QuoteValidity;
use App\Domain\Order\Totals;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\QuoteSnapshot;
use App\Settings\BusinessSettings;
use App\Settings\DocumentSettings;
use InvalidArgumentException;

/** @phpstan-import-type QuoteLine from QuoteLinesComparison */
final class BuildQuoteDocument
{
    public function __construct(private BusinessSettings $business, private DocumentSettings $documents) {}

    public function handle(Order $order, QuoteSnapshot $snapshot): QuoteDocument
    {
        if ($snapshot->order_id !== $order->id) {
            throw new InvalidArgumentException('The quote revision must belong to this order.');
        }
        /** @var list<QuoteLine> $snapshotLines */
        $snapshotLines = $snapshot->lines;
        $totalLines = [];
        $lines = [];
        foreach ($snapshotLines as $line) {
            $totalLines[] = ['kind' => OrderLineKind::from($line['kind']), 'qty' => $line['qty'], 'unitPriceCents' => $line['unit_price_cents']];
            $lines[] = [
                'description' => $line['description'], 'sku' => $line['sku'], 'qty' => $line['qty'], 'saleUnit' => $line['sale_unit'],
                'unitPrice' => Lkr::formatWithCurrency($line['unit_price_cents']), 'lineTotal' => Lkr::formatWithCurrency($line['line_total_cents']),
            ];
        }
        $totals = Totals::calculate($totalLines, $snapshot->required_advance_cents);
        $expired = QuoteValidity::isExpired(now()->getTimestamp(), $snapshot->valid_until?->getTimestamp());
        $notice = match ($order->status) {
            OrderStatus::Cancelled => 'This quote has been withdrawn.',
            OrderStatus::Expired => 'This quote has expired.',
            OrderStatus::Quoted => $expired ? 'This quote has expired.' : null,
            default => 'This quote has been accepted.',
        };
        $reply = 'Hello, I would like to accept quote '.$order->reference.' (revision '.$snapshot->revision.').';

        return new QuoteDocument(
            $this->business->name, $this->business->address, $this->business->phone, $this->business->email, $this->documents->quote_footer,
            $order->reference, $snapshot->revision, $snapshot->created_at->copy()->setTimezone('Asia/Colombo')->format('d M Y H:i'),
            $order->customer_name, $order->phone_e164, $lines,
            Lkr::formatWithCurrency($totals->subtotalCents), Lkr::formatWithCurrency($totals->deliveryCents),
            Lkr::formatWithCurrency($totals->discountCents), Lkr::formatWithCurrency($totals->totalCents),
            $snapshot->required_advance_cents === null ? null : Lkr::formatWithCurrency($snapshot->required_advance_cents),
            $snapshot->exclusions, $snapshot->valid_until?->copy()->setTimezone('Asia/Colombo')->format('d M Y'), $notice,
            $notice === null ? QuoteMessage::whatsAppUrl($this->business->whatsapp_digits === '' ? null : $this->business->whatsapp_digits, $reply) : null,
        );
    }
}
