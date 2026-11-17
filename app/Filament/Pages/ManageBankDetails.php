<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminSettingsPage;
use App\Filament\Support\SettingsFields;
use App\Settings\BankSettings;
use Filament\Schemas\Schema;

class ManageBankDetails extends AdminSettingsPage
{
    protected static string $settings = BankSettings::class;

    protected static ?string $title = 'Bank details';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            SettingsFields::text('account_name'), SettingsFields::text('bank_name'),
            SettingsFields::text('branch'), SettingsFields::text('account_number'),
        ]);
    }
}
