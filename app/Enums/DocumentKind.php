<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentKind: string implements HasLabel
{
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case CreditNote = 'credit_note';
    case GoodsReceipt = 'goods_receipt';

    public function getLabel(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::Receipt => 'Receipt',
            self::CreditNote => 'Credit note',
            self::GoodsReceipt => 'Goods receipt',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Invoice => 'INV',
            self::Receipt => 'RCP',
            self::CreditNote => 'CRN',
            self::GoodsReceipt => 'GRN',
        };
    }
}
