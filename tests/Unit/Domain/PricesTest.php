<?php

use App\Domain\Catalog\Prices;

it('rejects non-positive retail prices', function (int $price) {
    expect(fn () => Prices::ensureRetail($price))->toThrow(InvalidArgumentException::class, 'Price must be greater than zero');
})->with([0, -1]);

it('allows retail and trade prices at their boundaries', function () {
    Prices::ensureRetail(1);
    Prices::ensureTrade(1, 1);
    Prices::ensureTrade(1, 0);
    expect(fn () => Prices::ensureTrade(1, 2))->toThrow(InvalidArgumentException::class, 'Trade price must not exceed price');
});
