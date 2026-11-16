<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentKind: string implements HasLabel
{
    case Payment = 'payment';
    case Refund = 'refund';

    public function getLabel(): string
    {
        return match ($this) {
            self::Payment => 'Payment',
            self::Refund => 'Refund',
        };
    }
}
