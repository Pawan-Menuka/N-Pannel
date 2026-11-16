<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BankSettings extends Settings
{
    public string $account_name;

    public string $bank_name;

    public string $branch;

    public string $account_number;

    public static function group(): string
    {
        return 'bank';
    }
}
