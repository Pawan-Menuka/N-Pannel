<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminSettingsPage;
use App\Filament\Support\SettingsFields;
use App\Settings\DocumentSettings;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ManageDocuments extends AdminSettingsPage
{
    protected static string $settings = DocumentSettings::class;

    protected static ?string $title = 'Documents';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('invoice_due_days')->numeric()->integer()->minValue(0)->maxValue(365)->required(),
            SettingsFields::textarea('quote_footer'), SettingsFields::textarea('invoice_footer'),
            Toggle::make('show_bank_details_on_invoice')->label('Show bank details on invoices'),
        ]);
    }
}
