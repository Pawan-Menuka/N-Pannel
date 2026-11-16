<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SaleUnit: string implements HasLabel
{
    case Sheet = 'sheet';
    case Panel = 'panel';
    case Box = 'box';
    case Pack = 'pack';
    case Piece = 'piece';
    case Metre = 'metre';
    case Roll = 'roll';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sheet => 'Sheet',
            self::Panel => 'Panel',
            self::Box => 'Box',
            self::Pack => 'Pack',
            self::Piece => 'Piece',
            self::Metre => 'Metre',
            self::Roll => 'Roll',
        };
    }
}
