<?php

namespace App\Domain\Order;

use InvalidArgumentException;

final class OrderReference
{
    private const string Alphabet = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** @param callable(int, int): int $randomInt */
    public static function generate(string $prefix, callable $randomInt): string
    {
        if (! in_array($prefix, ['Q', 'O'], true)) {
            throw new InvalidArgumentException('An order reference prefix must be Q or O.');
        }
        $reference = $prefix.'-';
        for ($index = 0; $index < 6; $index++) {
            $reference .= self::Alphabet[$randomInt(0, strlen(self::Alphabet) - 1)];
        }

        return $reference;
    }
}
