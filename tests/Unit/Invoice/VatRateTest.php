<?php

use App\Domain\Invoice\VatRate;

it('parses and formats VAT percentages without floats', function (string $percent, int $bp, string $formatted) {
    expect(VatRate::parse($percent))->toBe($bp);
    expect(VatRate::format($bp))->toBe($formatted);
})->with([['18', 1800, '18'], ['12.5', 1250, '12.5'], ['18.00', 1800, '18'], ['0.01', 1, '0.01'], ['100', 10000, '100'], ['0', 0, '0'], [' 12.50 ', 1250, '12.5']]);

it('rejects invalid percentage strings', function (string $percent) {
    expect(fn () => VatRate::parse($percent))->toThrow(InvalidArgumentException::class);
})->with(['18.555', '-1', '100.01', '101', '1e1', '', '18%', '18,5', '.5']);
