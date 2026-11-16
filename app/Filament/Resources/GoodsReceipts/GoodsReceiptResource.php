<?php

namespace App\Filament\Resources\GoodsReceipts;

use App\Domain\Money\Lkr;
use App\Enums\GoodsReceiptStatus;
use App\Filament\Resources\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\EditGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Filament\Resources\GoodsReceipts\Pages\ViewGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\RelationManagers\LinesRelationManager;
use App\Models\GoodsReceipt;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** @extends resource<GoodsReceipt> */
class GoodsReceiptResource extends Resource
{
    protected static ?string $model = GoodsReceipt::class;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Goods received';

    protected static ?string $modelLabel = 'goods received note';

    protected static ?string $pluralModelLabel = 'Goods received';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')->relationship('supplier', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true))
                ->searchable()->preload()->required(),
            DatePicker::make('received_on')->required()->default(fn (): string => now('Asia/Colombo')->toDateString()),
            TextInput::make('supplier_reference')->maxLength(255),
            Textarea::make('note')->maxLength(1000),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('number')->placeholder('Draft'), TextEntry::make('supplier.name')->label('Supplier'),
            TextEntry::make('received_on')->date(), TextEntry::make('status')->badge(),
            TextEntry::make('supplier_reference'), TextEntry::make('note'),
            TextEntry::make('total_cost_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextEntry::make('createdBy.name')->label('Created by'),
            TextEntry::make('postedBy.name')->label('Posted by'), TextEntry::make('posted_at')->dateTime(),
            TextEntry::make('voidedBy.name')->label('Voided by'), TextEntry::make('voided_at')->dateTime(),
            TextEntry::make('void_reason'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with('supplier'))->defaultSort('id', 'desc')->columns([
            TextColumn::make('number')->placeholder('Draft')->searchable(), TextColumn::make('supplier.name')->label('Supplier')->searchable(),
            TextColumn::make('received_on')->date()->sortable(), TextColumn::make('status')->badge(),
            TextColumn::make('total_cost_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('lines_count')->counts('lines')->label('Lines'),
        ])->filters([
            SelectFilter::make('status')->options(GoodsReceiptStatus::class),
            SelectFilter::make('supplier')->relationship('supplier', 'name')->searchable()->preload(),
        ])->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoodsReceipts::route('/'), 'create' => CreateGoodsReceipt::route('/create'),
            'view' => ViewGoodsReceipt::route('/{record}'), 'edit' => EditGoodsReceipt::route('/{record}/edit'),
        ];
    }
}
