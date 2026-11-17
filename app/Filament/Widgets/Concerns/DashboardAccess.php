<?php

namespace App\Filament\Widgets\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Facades\Filament;

trait DashboardAccess
{
    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->is_active && in_array($user->role, static::allowedRoles(), true);
    }

    /** @return list<UserRole> */
    protected static function allowedRoles(): array
    {
        return [UserRole::Admin, UserRole::Sales];
    }
}
