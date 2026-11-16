<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderEventType: string implements HasLabel
{
    case Status = 'status';
    case Payment = 'payment';
    case Note = 'note';
    case Attention = 'attention';

    public function getLabel(): string
    {
        return match ($this) {
            self::Status => 'Status',
            self::Payment => 'Payment',
            self::Note => 'Note',
            self::Attention => 'Attention',
        };
    }
}
