<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\GoodsReceiptsRelationManager;
use App\Filament\Support\SupplierDeleteAction;
use App\Models\Supplier;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** @extends resource<Supplier> */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('phone_e164')->label('Phone')->tel(),
            TextInput::make('email')->email()->maxLength(255),
            Textarea::make('address'), Textarea::make('notes'),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'), TextEntry::make('phone_e164')->label('Phone'),
            TextEntry::make('email'), TextEntry::make('address'), TextEntry::make('notes'),
            IconEntry::make('is_active')->label('Active')->boolean(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('phone_e164')->label('Phone')->searchable(),
            IconColumn::make('is_active')->label('Active')->boolean(),
            TextColumn::make('goods_receipts_count')->counts('goodsReceipts')->label('Goods receipts'),
        ])->filters([TernaryFilter::make('is_active')->label('Active')])
            ->recordActions([ViewAction::make(), EditAction::make(), SupplierDeleteAction::make()]);
    }

    public static function getRelations(): array
    {
        return [GoodsReceiptsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'), 'create' => CreateSupplier::route('/create'),
            'view' => ViewSupplier::route('/{record}'), 'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
