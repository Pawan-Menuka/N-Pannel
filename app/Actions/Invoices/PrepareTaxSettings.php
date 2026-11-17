<?php

namespace App\Actions\Invoices;

use App\Domain\Invoice\TaxRegistration;
use App\Domain\Invoice\VatRate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PrepareTaxSettings
{
    /** @return array{vat_number: ?string, vat_rate_bp: ?int} */
    public function handle(?string $vatNumber, ?string $percent): array
    {
        $number = trim($vatNumber ?? '');
        $percent = trim($percent ?? '');
        try {
            $rate = $percent === '' ? null : VatRate::parse($percent);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['vat_percent' => $exception->getMessage()]);
        }
        try {
            TaxRegistration::isRegistered($number, $rate);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['vat_number' => $exception->getMessage(), 'vat_percent' => $exception->getMessage()]);
        }

        return ['vat_number' => $number === '' ? null : $number, 'vat_rate_bp' => $rate];
    }
}
