<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class TaxSettings extends Settings
{
    public bool $prices_include_vat;

    public ?int $vat_rate_bp;

    public ?string $vat_number;

    public static function group(): string
    {
        return 'tax';
    }
}
