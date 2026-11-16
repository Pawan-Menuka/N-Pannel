<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class QuoteSettings extends Settings
{
    public int $validity_days;

    public int $default_advance_pct;

    public static function group(): string
    {
        return 'quotes';
    }
}
