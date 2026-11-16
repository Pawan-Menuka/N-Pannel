<?php

use App\Domain\Purchasing\AmountTooLarge;
use App\Domain\Purchasing\ReceiptTotals;

it('calculates integer receipt amounts including the maximum supported amount', function () {
    expect(ReceiptTotals::lineTotal(50, 125050))->toBe(6252500);
    expect(ReceiptTotals::sum([6252500, 2500]))->toBe(6255000);
    expect(ReceiptTotals::lineTotal(1, PHP_INT_MAX))->toBe(PHP_INT_MAX);
    expect(ReceiptTotals::lineTotal(0, PHP_INT_MAX))->toBe(0);
    expect(ReceiptTotals::sum([PHP_INT_MAX - 1, 1]))->toBe(PHP_INT_MAX);
    expect(ReceiptTotals::sum([]))->toBe(0);
});

it('refuses multiplication overflow before multiplying', function () {
    expect(fn () => ReceiptTotals::lineTotal(2, PHP_INT_MAX))->toThrow(AmountTooLarge::class);
});

it('refuses overflow while summing', function () {
    expect(fn () => ReceiptTotals::sum([PHP_INT_MAX, 1]))->toThrow(AmountTooLarge::class);
});

it('refuses negative amounts and quantities', function () {
    expect(fn () => ReceiptTotals::lineTotal(-1, 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => ReceiptTotals::lineTotal(1, -1))->toThrow(InvalidArgumentException::class);
    expect(fn () => ReceiptTotals::sum([-1]))->toThrow(InvalidArgumentException::class);
});
