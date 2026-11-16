<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

final class Measure
{
    public static function parseMillimetresToMicrons(string $mm): int
    {
        if (! preg_match('/\A(\d+)(?:\.(\d{1,3}))?\z/', trim($mm), $matches)) {
            throw new InvalidArgumentException('Enter millimetres with at most three decimal places');
        }

        $whole = ltrim($matches[1], '0');
        if (strlen($whole) > 7) {
            throw new InvalidArgumentException('Thickness is too large');
        }

        $microns = ((int) $whole * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
        if ($microns > 2147483647) {
            throw new InvalidArgumentException('Thickness is too large');
        }

        return $microns;
    }

    public static function micronsToMillimetres(int $microns): string
    {
        self::nonNegative($microns);
        $fraction = rtrim(str_pad((string) ($microns % 1000), 3, '0', STR_PAD_LEFT), '0');

        return (string) intdiv($microns, 1000).($fraction === '' ? '' : '.'.$fraction);
    }

    public static function feetAndInches(int $mm): string
    {
        self::nonNegative($mm);
        $quarters = (int) self::roundedRatio([$mm, 40], [254]);
        $inches = intdiv($quarters % 48, 4);
        $fraction = ['', ' 1/4', ' 1/2', ' 3/4'][$quarters % 4];

        return intdiv($quarters, 48)."' ".$inches.$fraction.'"';
    }

    public static function squareFeet(int $lengthMm, int $widthMm): string
    {
        return self::squareFeetPerUnit($lengthMm, $widthMm, 1);
    }

    public static function squareFeetPerUnit(int $lengthMm, int $widthMm, int $piecesPerUnit): string
    {
        self::nonNegative($lengthMm, $widthMm, $piecesPerUnit);
        $hundredths = str_pad(self::roundedRatio([$lengthMm, $widthMm, $piecesPerUnit, 10000], [9290304]), 3, '0', STR_PAD_LEFT);

        return substr($hundredths, 0, -2).'.'.substr($hundredths, -2);
    }

    public static function pricePerSquareFootCents(int $priceCents, int $lengthMm, int $widthMm, int $piecesPerUnit): ?int
    {
        self::nonNegative($priceCents, $lengthMm, $widthMm, $piecesPerUnit);
        if ($lengthMm === 0 || $widthMm === 0 || $piecesPerUnit === 0) {
            return null;
        }

        $result = self::roundedRatio([$priceCents, 9290304], [$lengthMm, $widthMm, $piecesPerUnit, 100]);
        if (self::compare($result, (string) PHP_INT_MAX) > 0) {
            throw new InvalidArgumentException('Price per square foot is too large');
        }

        return (int) $result;
    }

    /**
     * Decimal long arithmetic keeps intermediate areas and prices out of floating point,
     * even for the largest dimensions and pack sizes supported by the database.
     *
     * @param  list<int>  $numeratorFactors
     * @param  list<int>  $denominatorFactors
     */
    private static function roundedRatio(array $numeratorFactors, array $denominatorFactors): string
    {
        $numerator = '1';
        $denominator = '1';
        foreach ($numeratorFactors as $factor) {
            $numerator = self::multiply($numerator, (string) $factor);
        }
        foreach ($denominatorFactors as $factor) {
            $denominator = self::multiply($denominator, (string) $factor);
        }
        $quotient = '';
        $remainder = '0';
        foreach (str_split($numerator) as $digit) {
            $remainder = ltrim($remainder.$digit, '0') ?: '0';
            $next = 0;
            while (self::compare($remainder, $denominator) >= 0) {
                $remainder = self::subtract($remainder, $denominator);
                $next++;
            }
            $quotient .= (string) $next;
        }
        $quotient = ltrim($quotient, '0') ?: '0';
        if (self::compare(self::multiply($remainder, '2'), $denominator) >= 0) {
            $digits = str_split($quotient);
            $carry = 1;
            for ($index = count($digits) - 1; $index >= 0 && $carry; $index--) {
                $value = (int) $digits[$index] + $carry;
                $digits[$index] = (string) ($value % 10);
                $carry = intdiv($value, 10);
            }
            $quotient = ($carry ? '1' : '').implode('', $digits);
        }

        return $quotient;
    }

    private static function multiply(string $left, string $right): string
    {
        $digits = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $value = $digits[$position] + ((int) $left[$i] * (int) $right[$j]);
                $digits[$position] = $value % 10;
                $digits[$position - 1] += intdiv($value, 10);
            }
        }

        return ltrim(implode('', $digits), '0') ?: '0';
    }

    private static function compare(string $left, string $right): int
    {
        return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
    }

    private static function subtract(string $left, string $right): string
    {
        $right = str_pad($right, strlen($left), '0', STR_PAD_LEFT);
        $digits = str_split($left);
        $borrow = 0;
        for ($index = count($digits) - 1; $index >= 0; $index--) {
            $value = (int) $digits[$index] - (int) $right[$index] - $borrow;
            $borrow = $value < 0 ? 1 : 0;
            $digits[$index] = (string) ($value < 0 ? $value + 10 : $value);
        }

        return ltrim(implode('', $digits), '0') ?: '0';
    }

    private static function nonNegative(int ...$values): void
    {
        foreach ($values as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Measurements must not be negative');
            }
        }
    }
}
