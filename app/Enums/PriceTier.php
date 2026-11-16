<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PriceTier: string implements HasLabel
{
    case Retail = 'retail';
    case Trade = 'trade';

    public function getLabel(): string
    {
        return match ($this) {
            self::Retail => 'Retail',
            self::Trade => 'Trade',
        };
    }
}
