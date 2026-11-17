<?php

namespace App\Filament\Support;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\SettingsPage;
use UnitEnum;

abstract class AdminSettingsPage extends SettingsPage
{
    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->is_active && $user->role === UserRole::Admin;
    }

    public function canEdit(): bool
    {
        return static::canAccess();
    }
}
