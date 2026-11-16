<?php

namespace App\Filament\Resources\Stocktakes;

use App\Enums\StocktakeStatus;
use App\Filament\Resources\Stocktakes\Pages\CreateStocktake;
use App\Filament\Resources\Stocktakes\Pages\EditStocktake;
use App\Filament\Resources\Stocktakes\Pages\ListStocktakes;
use App\Filament\Resources\Stocktakes\Pages\ViewStocktake;
use App\Filament\Resources\Stocktakes\RelationManagers\LinesRelationManager;
use App\Models\Stocktake;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** @extends resource<Stocktake> */
class StocktakeResource extends Resource
{
    protected static ?string $model = Stocktake::class;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('note')->maxLength(1000)->disabled(fn (?Stocktake $record): bool => $record !== null && $record->status !== StocktakeStatus::Draft),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label('Stocktake')->formatStateUsing(fn (int $state): string => '#'.$state),
            TextEntry::make('status')->badge(),
            TextEntry::make('note'),
            TextEntry::make('createdBy.name')->label('Created by'),
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('postedBy.name')->label('Posted by'),
            TextEntry::make('posted_at')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with('createdBy'))->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('Stocktake')->formatStateUsing(fn (int $state): string => '#'.$state),
            TextColumn::make('status')->badge(),
            TextColumn::make('lines_count')->counts('lines')->label('Lines'),
            TextColumn::make('note')->limit(80),
            TextColumn::make('createdBy.name')->label('Created by'),
            TextColumn::make('created_at')->dateTime(),
            TextColumn::make('posted_at')->dateTime(),
        ])->filters([
            SelectFilter::make('status')->options(StocktakeStatus::class),
        ])->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStocktakes::route('/'),
            'create' => CreateStocktake::route('/create'),
            'view' => ViewStocktake::route('/{record}'),
            'edit' => EditStocktake::route('/{record}/edit'),
        ];
    }
}
