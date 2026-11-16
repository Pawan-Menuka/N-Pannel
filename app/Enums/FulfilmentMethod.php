<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FulfilmentMethod: string implements HasLabel
{
    case Delivery = 'delivery';
    case Collection = 'collection';

    public function getLabel(): string
    {
        return match ($this) {
            self::Delivery => 'Delivery',
            self::Collection => 'Collection',
        };
    }
}
