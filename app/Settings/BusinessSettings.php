<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BusinessSettings extends Settings
{
    public string $name;

    public string $phone;

    public string $whatsapp_digits;

    public string $email;

    public string $address;

    public string $response_hours;

    public static function group(): string
    {
        return 'business';
    }
}
