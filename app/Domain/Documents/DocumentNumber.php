<?php

namespace App\Domain\Documents;

use InvalidArgumentException;

final class DocumentNumber
{
    public static function format(string $prefix, int $year, int $number): string
    {
        if (trim($prefix) === '' || $year < 2000 || $year > 9999 || $number < 1) {
            throw new InvalidArgumentException('A prefix, a year between 2000 and 9999, and a positive number are required.');
        }

        return $prefix.'-'.$year.'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
