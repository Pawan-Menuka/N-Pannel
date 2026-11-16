<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CustomerType: string implements HasLabel
{
    case Homeowner = 'homeowner';
    case Contractor = 'contractor';
    case Dealer = 'dealer';
    case Company = 'company';

    public function getLabel(): string
    {
        return match ($this) {
            self::Homeowner => 'Homeowner',
            self::Contractor => 'Contractor',
            self::Dealer => 'Dealer',
            self::Company => 'Company',
        };
    }
}
