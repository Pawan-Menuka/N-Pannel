<?php

use App\Domain\Money\InvalidAmount;
use App\Domain\Money\Lkr;

it('parses rupees without floating point', function (string $text, int $cents) {
    expect(Lkr::parse($text))->toBe($cents);
})->with([
    ['1250', 125000], ['1,250', 125000], ['1250.5', 125050], ['1,250.50', 125050],
    [' 1250.00 ', 125000], ['Rs 1250', 125000], ['rs. 1250', 125000], ['lKr 1250', 125000],
    ['9,999,999,999.99', 999999999999], ['0.01', 1], ['0000.10', 10],
    ['Rs.1,250', 125000],
]);

it('rejects invalid amounts', function (string $text) {
    expect(fn () => Lkr::parse($text))->toThrow(InvalidAmount::class);
})->with(['', ' ', '-1', '1.001', 'abc', '1.2.3', '12,50', '1,2500', '1,23,456', '10,000,000,000', '10000000000', '+1', '.5', '1.']);

it('formats integer cents', function () {
    expect(Lkr::format(125050))->toBe('1,250.50');
    expect(Lkr::formatWithCurrency(125050))->toBe('Rs 1,250.50');
    expect(Lkr::format(0))->toBe('0.00');
});
