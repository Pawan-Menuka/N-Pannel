<?php

namespace App\Domain\Order;

/**
 * @phpstan-type QuoteLine array{kind: string, variant_id: int|null, sku: string|null, description: string, sale_unit: string|null, qty: int, unit_price_cents: int, line_total_cents: int, sort_order: int}
 * @phpstan-type QuoteData array{lines: list<QuoteLine>, required_advance_cents: int|null, exclusions: string|null, valid_until: int|null, total_cents: int}
 */
final class QuoteLinesComparison
{
    /**
     * @param  QuoteData  $current
     * @param  QuoteData  $snapshot
     */
    public static function same(array $current, array $snapshot): bool
    {
        return self::normalize($current) === self::normalize($snapshot);
    }

    /**
     * @param  QuoteData  $content
     * @return QuoteData
     */
    private static function normalize(array $content): array
    {
        $lines = [];
        foreach ($content['lines'] as $line) {
            $lines[] = [
                'kind' => $line['kind'], 'variant_id' => $line['variant_id'], 'sku' => $line['sku'],
                'description' => $line['description'], 'sale_unit' => $line['sale_unit'], 'qty' => $line['qty'],
                'unit_price_cents' => $line['unit_price_cents'], 'line_total_cents' => $line['line_total_cents'], 'sort_order' => $line['sort_order'],
            ];
        }
        usort($lines, static fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        return ['lines' => $lines, 'required_advance_cents' => $content['required_advance_cents'], 'exclusions' => $content['exclusions'], 'valid_until' => $content['valid_until'], 'total_cents' => $content['total_cents']];
    }
}
