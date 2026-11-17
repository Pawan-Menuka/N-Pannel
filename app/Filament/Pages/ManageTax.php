<?php

namespace App\Filament\Pages;

use App\Actions\Invoices\PrepareTaxSettings;
use App\Domain\Invoice\VatRate;
use App\Filament\Support\AdminSettingsPage;
use App\Filament\Support\PurchasingValidation;
use App\Filament\Support\SettingsFields;
use App\Settings\TaxSettings;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ManageTax extends AdminSettingsPage
{
    protected static string $settings = TaxSettings::class;

    protected static ?string $title = 'Tax';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            SettingsFields::text('vat_number')->label('VAT number'),
            TextInput::make('vat_percent')->label('VAT rate')->suffix('%')->inputMode('decimal')
                ->helperText('Leave both empty if the business is not VAT registered. Prices include VAT.')
                ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        VatRate::parse((string) $value);
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                }]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['vat_percent'] = $data['vat_rate_bp'] === null ? null : VatRate::format((int) $data['vat_rate_bp']);
        unset($data['vat_rate_bp'], $data['prices_include_vat']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        try {
            return app(PrepareTaxSettings::class)->handle($data['vat_number'] ?? null, $data['vat_percent'] ?? null);
        } catch (ValidationException $exception) {
            throw PurchasingValidation::forForm($exception);
        }
    }
}
