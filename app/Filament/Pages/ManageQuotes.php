<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminSettingsPage;
use App\Settings\QuoteSettings;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ManageQuotes extends AdminSettingsPage
{
    protected static string $settings = QuoteSettings::class;

    protected static ?string $title = 'Quotes';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('validity_days')->numeric()->integer()->minValue(1)->maxValue(365)->required(),
            TextInput::make('default_advance_pct')->label('Default advance')->suffix('%')->numeric()->integer()->minValue(0)->maxValue(100)->required(),
        ]);
    }
}
