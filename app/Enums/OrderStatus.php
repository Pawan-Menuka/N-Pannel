<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel
{
    case Quoted = 'quoted';
    case Placed = 'placed';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case ReadyForCollection = 'ready_for_collection';
    case Dispatched = 'dispatched';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Quoted => 'Quoted',
            self::Placed => 'Placed',
            self::Confirmed => 'Confirmed',
            self::Processing => 'Processing',
            self::ReadyForCollection => 'Ready for collection',
            self::Dispatched => 'Dispatched',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }
}
