<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockStatus: string implements HasLabel
{
    case InStock = 'in_stock';
    case MadeToOrder = 'made_to_order';
    case OutOfStock = 'out_of_stock';

    public function getLabel(): string
    {
        return match ($this) {
            self::InStock => 'In stock',
            self::MadeToOrder => 'Made to order',
            self::OutOfStock => 'Out of stock',
        };
    }
}
