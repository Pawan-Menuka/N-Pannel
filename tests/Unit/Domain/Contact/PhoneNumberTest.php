<?php

use App\Domain\Contact\InvalidPhoneNumber;
use App\Domain\Contact\PhoneNumber;

it('normalizes local and international phone numbers', function (string $input, string $expected) {
    expect(PhoneNumber::toE164($input))->toBe($expected);
})->with([
    ['0771234567', '+94771234567'], ['771234567', '+94771234567'],
    ['+94 77 123 4567', '+94771234567'], ['0094771234567', '+94771234567'],
    ['011-2345678', '+94112345678'], ['(077) 123.4567', '+94771234567'],
    ['+44 20 7946 0958', '+442079460958'],
]);

it('refuses malformed or incorrectly sized phone numbers', function (string $input) {
    expect(fn () => PhoneNumber::toE164($input))->toThrow(InvalidPhoneNumber::class);
})->with(['123', '077123456789', '077ABC4567', '+9477123456', '+947712345678', '+0123456789', '+1234567890123456', '', '00123']);
