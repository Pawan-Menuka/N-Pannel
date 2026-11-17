<?php

use App\Domain\Order\OrderReference;

it('generates six unambiguous characters for each supported prefix', function (string $prefix) {
    $reference = OrderReference::generate($prefix, random_int(...));

    expect($reference)->toMatch('/^'.$prefix.'-[23456789ABCDEFGHJKMNPQRSTVWXYZ]{6}$/D');
})->with(['Q', 'O']);

it('uses the injected random integer source with the alphabet bounds', function () {
    $offset = 0;
    $random = function (int $min, int $max) use (&$offset): int {
        expect($min)->toBe(0);
        expect($max)->toBe(29);

        return $offset++;
    };

    expect(OrderReference::generate('O', $random))->toBe('O-234567');
    expect(OrderReference::generate('Q', fn (int $min, int $max): int => $max))->toBe('Q-ZZZZZZ');
});

it('refuses unsupported prefixes before calling the random source', function (string $prefix) {
    expect(fn () => OrderReference::generate($prefix, function (int $min, int $max): int {
        throw new RuntimeException('Must not be called');
    }))->toThrow(InvalidArgumentException::class);
})->with(['', 'o', 'INV', 'O-']);
