<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockReason: string implements HasLabel
{
    case GoodsReceived = 'goods_received';
    case GoodsReceivedVoid = 'goods_received_void';
    case CounterSale = 'counter_sale';
    case QuoteAccepted = 'quote_accepted';
    case OrderCancelled = 'order_cancelled';
    case ReturnRestock = 'return_restock';
    case ManualAdjust = 'manual_adjust';
    case Stocktake = 'stocktake';
    case Import = 'import';

    public function getLabel(): string
    {
        return match ($this) {
            self::GoodsReceived => 'Goods received',
            self::GoodsReceivedVoid => 'Goods received void',
            self::CounterSale => 'Counter sale',
            self::QuoteAccepted => 'Quote accepted',
            self::OrderCancelled => 'Order cancelled',
            self::ReturnRestock => 'Return restock',
            self::ManualAdjust => 'Manual adjust',
            self::Stocktake => 'Stocktake',
            self::Import => 'Import',
        };
    }
}
