<?php

namespace App\Domain\Invoice;

use InvalidArgumentException;

final class VatRate
{
    public static function parse(string $percent): int
    {
        if (! preg_match('/\A(0|[1-9]\d{0,2})(?:\.(\d{1,2}))?\z/', trim($percent), $matches)) {
            throw new InvalidArgumentException('Enter a VAT percentage from 0 to 100 with at most two decimal places.');
        }
        $bp = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($bp > 10000) {
            throw new InvalidArgumentException('The VAT percentage cannot exceed 100.');
        }

        return $bp;
    }

    public static function format(int $bp): string
    {
        if ($bp < 0 || $bp > 10000) {
            throw new InvalidArgumentException('The VAT rate must be between 0 and 10,000 basis points.');
        }
        $fraction = rtrim(str_pad((string) ($bp % 100), 2, '0', STR_PAD_LEFT), '0');

        return (string) intdiv($bp, 100).($fraction === '' ? '' : '.'.$fraction);
    }
}
