<?php

namespace App\Domain\Money;

final class Lkr
{
    public static function parse(string $input): int
    {
        $text = preg_replace('/^(?:Rs\.?|LKR)\s*/i', '', trim($input)) ?? '';

        if (! preg_match('/\A(\d+|[1-9]\d{0,2}(?:,\d{3})+)(?:\.(\d{1,2}))?\z/', $text, $matches)) {
            throw new InvalidAmount('Enter an amount like 1,250.00');
        }

        $rupees = ltrim(str_replace(',', '', $matches[1]), '0');

        if (strlen($rupees) > 10) {
            throw new InvalidAmount('Amount exceeds 9,999,999,999.99');
        }

        return ((int) $rupees * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public static function format(int $cents): string
    {
        $negative = $cents < 0;
        $digits = ltrim((string) $cents, '-');
        $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -2);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return ($negative ? '-' : '').$grouped.'.'.substr($digits, -2);
    }

    public static function formatWithCurrency(int $cents): string
    {
        return 'Rs '.self::format($cents);
    }
}
