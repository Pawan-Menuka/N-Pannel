<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum GoodsReceiptStatus: string implements HasLabel
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Void => 'Void',
        };
    }
}
