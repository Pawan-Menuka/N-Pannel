<?php

use App\Domain\Invoice\TaxRegistration;

it('recognises complete tax registration or no registration', function (?string $number, ?int $rate, bool $expected) {
    expect(TaxRegistration::isRegistered($number, $rate))->toBe($expected);
})->with([[null, null, false], ['', null, false], ['  ', null, false], [' VAT ', 1800, true], ['VAT', 1, true], ['VAT', 10000, true]]);

it('refuses partial registration and invalid rates', function (?string $number, ?int $rate) {
    expect(fn () => TaxRegistration::isRegistered($number, $rate))
        ->toThrow(InvalidArgumentException::class, 'Tax settings are incomplete: set both the VAT number and rate, or neither.');
})->with([[null, 1800], ['VAT', null], ['VAT', 0], ['VAT', -1], ['VAT', 10001], [null, 0]]);
