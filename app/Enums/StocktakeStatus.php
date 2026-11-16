<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StocktakeStatus: string implements HasLabel
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }
}
