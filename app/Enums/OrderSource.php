<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderSource: string implements HasLabel
{
    case Quote = 'quote';
    case Counter = 'counter';
    case WebCheckout = 'web_checkout';

    public function getLabel(): string
    {
        return match ($this) {
            self::Quote => 'Quote',
            self::Counter => 'Counter',
            self::WebCheckout => 'Web checkout',
        };
    }
}
