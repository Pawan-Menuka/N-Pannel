<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

final class SettingsFields
{
    public static function text(string $name): TextInput
    {
        return TextInput::make($name)->maxLength(255)->dehydrateStateUsing(fn (?string $state): string => trim($state ?? ''));
    }

    public static function textarea(string $name): Textarea
    {
        return Textarea::make($name)->dehydrateStateUsing(fn (?string $state): string => trim($state ?? ''));
    }
}
