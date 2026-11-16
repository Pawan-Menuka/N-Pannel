<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentStatus: string implements HasLabel
{
    case Issued = 'issued';
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::Void => 'Void',
        };
    }
}
