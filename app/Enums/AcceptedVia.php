<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AcceptedVia: string implements HasLabel
{
    case Staff = 'staff';
    case Online = 'online';

    public function getLabel(): string
    {
        return match ($this) {
            self::Staff => 'Staff',
            self::Online => 'Online',
        };
    }
}
