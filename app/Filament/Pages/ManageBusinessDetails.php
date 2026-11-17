<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminSettingsPage;
use App\Filament\Support\SettingsFields;
use App\Settings\BusinessSettings;
use Filament\Schemas\Schema;

class ManageBusinessDetails extends AdminSettingsPage
{
    protected static string $settings = BusinessSettings::class;

    protected static ?string $title = 'Business details';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            SettingsFields::text('name')->required(), SettingsFields::text('phone'),
            SettingsFields::text('whatsapp_digits')->label('WhatsApp digits')->regex('/\A[0-9]+\z/'),
            SettingsFields::text('email')->email(), SettingsFields::textarea('address'), SettingsFields::text('response_hours'),
        ]);
    }
}
