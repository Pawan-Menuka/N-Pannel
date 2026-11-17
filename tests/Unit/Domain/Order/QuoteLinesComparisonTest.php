<?php

use App\Domain\Order\QuoteLinesComparison;

/** @return array{lines: list<array{kind: string, variant_id: int|null, sku: string|null, description: string, sale_unit: string|null, qty: int, unit_price_cents: int, line_total_cents: int, sort_order: int}>, required_advance_cents: int|null, exclusions: string|null, valid_until: int|null, total_cents: int} */
function quoteComparisonData(): array
{
    return [
        'lines' => [['kind' => 'product', 'variant_id' => 1, 'sku' => 'PANEL', 'description' => 'Panel', 'sale_unit' => 'piece', 'qty' => 2, 'unit_price_cents' => 100, 'line_total_cents' => 200, 'sort_order' => 0]],
        'required_advance_cents' => 50, 'exclusions' => 'Installation', 'valid_until' => 1000, 'total_cents' => 200,
    ];
}

it('compares normalized content independently of associative key order', function () {
    $current = quoteComparisonData();
    $snapshot = $current;
    $snapshot['lines'][0] = array_reverse($snapshot['lines'][0], true);
    expect(QuoteLinesComparison::same($current, $snapshot))->toBeTrue();
});

it('normalizes line list order by its explicit sort order', function () {
    $current = quoteComparisonData();
    $second = $current['lines'][0];
    $second['sort_order'] = 1;
    $second['sku'] = 'SECOND';
    $current['lines'][] = $second;
    $snapshot = $current;
    $snapshot['lines'] = array_reverse($snapshot['lines']);

    expect(QuoteLinesComparison::same($current, $snapshot))->toBeTrue();
});

it('detects each line change', function (string $key, mixed $value) {
    $current = quoteComparisonData();
    $snapshot = $current;
    $snapshot['lines'][0][$key] = $value;
    expect(QuoteLinesComparison::same($current, $snapshot))->toBeFalse();
})->with([
    ['kind', 'labour'], ['variant_id', null], ['sku', 'OTHER'], ['description', 'Changed'], ['sale_unit', 'metre'],
    ['qty', 3], ['unit_price_cents', 101], ['line_total_cents', 201], ['sort_order', 1],
]);

it('detects each quote term change', function (string $key, mixed $value) {
    $current = quoteComparisonData();
    $snapshot = $current;
    $snapshot[$key] = $value;
    expect(QuoteLinesComparison::same($current, $snapshot))->toBeFalse();
})->with([['required_advance_cents', null], ['exclusions', null], ['valid_until', 1001], ['total_cents', 201], ['lines', []]]);
