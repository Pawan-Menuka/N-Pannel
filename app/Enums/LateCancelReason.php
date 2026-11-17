<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum LateCancelReason: string implements HasLabel
{
    case DeliveryRefused = 'delivery_refused';
    case NotCollected = 'not_collected';
    case DamagedInTransit = 'damaged_in_transit';

    public function getLabel(): string
    {
        return match ($this) {
            self::DeliveryRefused => 'Delivery refused',
            self::NotCollected => 'Not collected',
            self::DamagedInTransit => 'Damaged in transit',
        };
    }
}
