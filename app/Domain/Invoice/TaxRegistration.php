<?php

namespace App\Domain\Invoice;

use InvalidArgumentException;

final class TaxRegistration
{
    public static function isRegistered(?string $number, ?int $rateBp): bool
    {
        $hasNumber = trim($number ?? '') !== '';
        if (! $hasNumber && $rateBp === null) {
            return false;
        }
        if ($hasNumber && $rateBp !== null && $rateBp >= 1 && $rateBp <= 10000) {
            return true;
        }

        throw new InvalidArgumentException('Tax settings are incomplete: set both the VAT number and rate, or neither.');
    }
}
