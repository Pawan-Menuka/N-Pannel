<?php

use App\Domain\Stock\InsufficientStock;
use App\Domain\Stock\StockLevelStatus;
use App\Domain\Stock\StocktakeVariance;

it('classifies stock against nullable reorder levels', function (int $qty, ?int $reorder, bool $low, bool $out) {
    expect(StockLevelStatus::isLow($qty, $reorder))->toBe($low);
    expect(StockLevelStatus::isOut($qty))->toBe($out);
})->with([[0, null, false, true], [0, 0, true, true], [-1, 5, true, true], [5, 5, true, false], [6, 5, false, false]]);

it('calculates signed stocktake variance', function () {
    expect(StocktakeVariance::delta(5, 3))->toBe(2);
    expect(StocktakeVariance::delta(0, 3))->toBe(-3);
    expect(StocktakeVariance::delta(5, 5))->toBe(0);
    expect(StocktakeVariance::delta(0, -3))->toBe(3);
    expect(StocktakeVariance::delta(0, -2147483648))->toBe(2147483648);
    expect(fn () => StocktakeVariance::delta(-1, 0))->toThrow(InvalidArgumentException::class);
});

it('describes a stock shortage', function () {
    $exception = new InsufficientStock(12, 5, 2);

    expect($exception->variantId)->toBe(12);
    expect($exception->requested)->toBe(5);
    expect($exception->available)->toBe(2);
    expect($exception->getMessage())->toBe('Only 2 in stock, 5 requested.');
});
