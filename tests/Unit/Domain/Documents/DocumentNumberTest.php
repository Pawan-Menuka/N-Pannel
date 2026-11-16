<?php

use App\Domain\Documents\DocumentNumber;

it('formats document numbers without truncating large sequences', function (int $number, string $expected) {
    expect(DocumentNumber::format('GRN', 2026, $number))->toBe($expected);
})->with([[1, 'GRN-2026-00001'], [99, 'GRN-2026-00099'], [100000, 'GRN-2026-100000']]);

it('requires a prefix year and positive sequence', function (string $prefix, int $year, int $number) {
    expect(fn () => DocumentNumber::format($prefix, $year, $number))->toThrow(InvalidArgumentException::class);
})->with([['', 2026, 1], [' ', 2026, 1], ['GRN', 1999, 1], ['GRN', 10000, 1], ['GRN', 2026, 0]]);
