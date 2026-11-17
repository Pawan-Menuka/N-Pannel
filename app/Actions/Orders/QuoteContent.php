<?php

namespace App\Actions\Orders;

use App\Domain\Order\QuoteLinesComparison;
use App\Models\Order;
use App\Models\QuoteSnapshot;

/**
 * @phpstan-import-type QuoteData from QuoteLinesComparison
 * @phpstan-import-type QuoteLine from QuoteLinesComparison
 */
final class QuoteContent
{
    /** @return QuoteData */
    public function handle(Order $quote): array
    {
        $lines = [];
        foreach ($quote->lines()->orderBy('sort_order')->orderBy('id')->get() as $line) {
            $lines[] = [
                'kind' => $line->kind->value, 'variant_id' => $line->variant_id, 'sku' => $line->sku,
                'description' => $line->description, 'sale_unit' => $line->sale_unit?->value,
                'qty' => $line->qty, 'unit_price_cents' => $line->unit_price_cents,
                'line_total_cents' => $line->line_total_cents, 'sort_order' => $line->sort_order,
            ];
        }

        return [
            'lines' => $lines, 'required_advance_cents' => $quote->required_advance_cents,
            'exclusions' => $quote->exclusions, 'valid_until' => $quote->valid_until?->getTimestamp(), 'total_cents' => $quote->total_cents,
        ];
    }

    /** @return QuoteData */
    public function snapshot(QuoteSnapshot $snapshot): array
    {
        /** @var list<QuoteLine> $lines */
        $lines = $snapshot->lines;

        return [
            'lines' => $lines, 'required_advance_cents' => $snapshot->required_advance_cents,
            'exclusions' => $snapshot->exclusions, 'valid_until' => $snapshot->valid_until?->getTimestamp(), 'total_cents' => $snapshot->total_cents,
        ];
    }
}
