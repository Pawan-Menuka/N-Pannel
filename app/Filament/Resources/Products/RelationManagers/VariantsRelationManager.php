<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Actions\Catalog\UniqueSku;
use App\Domain\Catalog\CatalogFormat;
use App\Domain\Catalog\Measure;
use App\Domain\Catalog\Prices;
use App\Domain\Money\InvalidAmount;
use App\Domain\Money\Lkr;
use App\Enums\SaleUnit;
use App\Enums\StockStatus;
use App\Filament\Support\CatalogDeleteAction;
use App\Filament\Support\MoneyInput;
use App\Models\Variant;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('sku')->label('SKU')->required()->maxLength(64)->rule('regex:'.CatalogFormat::SkuPattern)
                ->mutateStateForValidationUsing(fn (mixed $state): string => mb_strtoupper(trim((string) $state)))
                ->dehydrateStateUsing(fn (mixed $state): string => mb_strtoupper(trim((string) $state)))
                ->rule(fn (?Variant $record): UniqueSku => new UniqueSku($record)),
            TextInput::make('label')->maxLength(255),
            TextInput::make('colour')->maxLength(255),
            TextInput::make('length_mm')->label('Length (mm)')->integer()->minValue(0)->maxValue(2147483647)->live(onBlur: true),
            TextInput::make('width_mm')->label('Width (mm)')->integer()->minValue(0)->maxValue(2147483647)->live(onBlur: true),
            TextInput::make('thickness_micron')->label('Thickness (mm)')->inputMode('decimal')
                ->formatStateUsing(fn (mixed $state): ?string => $state === null || $state === '' ? null : Measure::micronsToMillimetres((int) $state))
                ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        Measure::parseMillimetresToMicrons((string) $value);
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                }])->dehydrateStateUsing(fn (mixed $state): ?int => $state === null || $state === '' ? null : Measure::parseMillimetresToMicrons((string) $state)),
            TextInput::make('weight_grams')->integer()->minValue(0)->maxValue(2147483647),
            Select::make('sale_unit')->options(SaleUnit::class)->enum(SaleUnit::class)->required(),
            TextInput::make('pieces_per_unit')->integer()->minValue(1)->maxValue(2147483647)->default(1)->required()->live(onBlur: true),
            MoneyInput::make('price_cents')->label('Price')->required()->live(onBlur: true)
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        Prices::ensureRetail(Lkr::parse((string) $value));
                    } catch (InvalidAmount) {
                        // The shared money rule supplies the parse error.
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                }),
            MoneyInput::make('trade_price_cents')->label('Trade price')
                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    try {
                        Prices::ensureTrade(Lkr::parse((string) $get('price_cents')), Lkr::parse((string) $value));
                    } catch (InvalidAmount) {
                        // The shared money rule supplies the parse error.
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                }),
            Toggle::make('track_stock')->default(true)->live(),
            TextInput::make('reorder_level')->integer()->minValue(0)->maxValue(2147483647)->visible(fn (Get $get): bool => (bool) $get('track_stock')),
            Select::make('stock_status')->options(StockStatus::class)->enum(StockStatus::class)->default(StockStatus::InStock->value)
                ->required()->visible(fn (Get $get): bool => ! $get('track_stock')),
            Toggle::make('is_active')->label('Active')->default(true),
            TextInput::make('sort_order')->label('Sort')->integer()->default(0)->required(),
            TextEntry::make('length_feet')->label('Length (feet / inches)')->state(fn (Get $get): string => Measure::feetAndInches(max(0, (int) $get('length_mm')))),
            TextEntry::make('width_feet')->label('Width (feet / inches)')->state(fn (Get $get): string => Measure::feetAndInches(max(0, (int) $get('width_mm')))),
            TextEntry::make('area')->label('ft² per unit')->state(fn (Get $get): string => Measure::squareFeetPerUnit(
                max(0, (int) $get('length_mm')), max(0, (int) $get('width_mm')), max(1, (int) $get('pieces_per_unit')),
            )),
            TextEntry::make('price_per_sqft')->label('Price per ft²')->state(function (Get $get): string {
                try {
                    $price = Measure::pricePerSquareFootCents(Lkr::parse((string) $get('price_cents')), max(0, (int) $get('length_mm')), max(0, (int) $get('width_mm')), max(1, (int) $get('pieces_per_unit')));

                    return $price === null ? '—' : Lkr::formatWithCurrency($price);
                } catch (InvalidAmount) {
                    return '—';
                }
            }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('sku')->defaultSort('sort_order')->columns([
            TextColumn::make('sku')->label('SKU')->searchable(),
            TextColumn::make('label'),
            TextColumn::make('size')->state(fn (Variant $record): string => ($record->length_mm ?? 0).' × '.($record->width_mm ?? 0).' mm')
                ->description(fn (Variant $record): string => Measure::feetAndInches($record->length_mm ?? 0).' × '.Measure::feetAndInches($record->width_mm ?? 0)),
            TextColumn::make('thickness_micron')->label('Thickness (mm)')->formatStateUsing(fn (int $state): string => Measure::micronsToMillimetres($state)),
            TextColumn::make('sale_unit'),
            TextColumn::make('price_cents')->label('Price')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('trade_price_cents')->label('Trade price')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('price_per_sqft')->label('Price / ft²')->state(function (Variant $record): string {
                $price = Measure::pricePerSquareFootCents($record->price_cents, $record->length_mm ?? 0, $record->width_mm ?? 0, $record->pieces_per_unit);

                return $price === null ? '—' : Lkr::formatWithCurrency($price);
            }),
            IconColumn::make('track_stock')->boolean(),
            IconColumn::make('is_active')->label('Active')->boolean(),
        ])->headerActions([CreateAction::make()])->recordActions([ViewAction::make(), EditAction::make(), CatalogDeleteAction::make()]);
    }
}
