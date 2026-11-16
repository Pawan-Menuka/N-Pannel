<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderLineKind: string implements HasLabel
{
    case Product = 'product';
    case Delivery = 'delivery';
    case Labour = 'labour';
    case Other = 'other';
    case Discount = 'discount';

    public function getLabel(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Delivery => 'Delivery',
            self::Labour => 'Labour',
            self::Other => 'Other',
            self::Discount => 'Discount',
        };
    }
}
