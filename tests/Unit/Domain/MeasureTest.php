<?php

use App\Domain\Catalog\Measure;

it('converts thickness losslessly', function (string $mm, int $microns) {
    expect(Measure::parseMillimetresToMicrons($mm))->toBe($microns);
    expect(Measure::micronsToMillimetres($microns))->toBe($mm);
})->with([['7.5', 7500], ['0.47', 470], ['9', 9000], ['0.001', 1], ['0', 0]]);

it('rejects invalid thickness', function (string $mm) {
    expect(fn () => Measure::parseMillimetresToMicrons($mm))->toThrow(InvalidArgumentException::class);
})->with(['', '-1', '1.0001', 'a', '1,000', '1.2.3', '.5', '999999999999999999999']);

it('rounds lengths to the nearest quarter inch', function (int $mm, string $expected) {
    expect(Measure::feetAndInches($mm))->toBe($expected);
})->with([[3048, '10\' 0"'], [2440, '8\' 0"'], [600, '1\' 11 1/2"'], [0, '0\' 0"']]);

it('calculates area and price per square foot using integer rounding', function () {
    expect(Measure::squareFeet(2440, 1220))->toBe('32.04');
    expect(Measure::pricePerSquareFootCents(125050, 2440, 1220, 1))->toBe(3903);
    expect(Measure::pricePerSquareFootCents(125050, 2440, 1220, 2))->toBe(1951);
    expect(Measure::pricePerSquareFootCents(1, 0, 1220, 1))->toBeNull();
    expect(Measure::pricePerSquareFootCents(1, 2440, 0, 1))->toBeNull();
    expect(Measure::pricePerSquareFootCents(1, 2440, 1220, 0))->toBeNull();
});

it('rounds half up without overflowing intermediate integer products', function () {
    expect(Measure::pricePerSquareFootCents(125050, 3048, 3048, 1))->toBe(1251);
    expect(Measure::pricePerSquareFootCents(999999999999, 2147483647, 2147483647, 2147483647))->toBe(0);
    expect(Measure::squareFeetPerUnit(2440, 1220, 2))->toBe('64.08');
    expect(Measure::squareFeet(304800, 304800))->toBe('1000000.00');
});
