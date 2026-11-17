<?php

use App\Domain\Invoice\Tax;

it('extracts inclusive VAT using integer half up rounding', function (int $total, int $rate, int $expected) {
    expect(Tax::inclusive($total, $rate))->toBe($expected);
})->with([
    [11800000, 1800, 1800000],
    [4525050, 1800, 690262],
    [0, 1800, 0],
    [1, 10000, 1],
    [2, 10000, 1],
    [3, 10000, 2],
    [3, 2000, 1],
    [2, 2000, 0],
    [4, 2000, 1],
    [10000000000000, 1800, 1525423728814],
]);

it('refuses invalid totals and rates', function (int $total, int $rate) {
    expect(fn () => Tax::inclusive($total, $rate))->toThrow(InvalidArgumentException::class);
})->with([[-1, 1800], [100, 0], [100, -1], [100, 10001]]);

it('refuses an overflowing product before PHP can promote it to float', function () {
    expect(fn () => Tax::inclusive(intdiv(PHP_INT_MAX, 1800) + 1, 1800))->toThrow(OverflowException::class);
    expect(Tax::inclusive(intdiv(PHP_INT_MAX, 10000), 10000))->toBe(461168601842739);
});
