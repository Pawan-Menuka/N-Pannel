<?php

namespace App\Filament\Resources\Stock\RelationManagers;

use App\Models\StockMovement;
use App\Models\Stocktake;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockMovements';

    protected static ?string $title = 'Movements';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'source']))
            ->defaultSort('created_at', 'desc')->columns([
                TextColumn::make('created_at')->label('When')->dateTime(),
                TextColumn::make('delta')->label('Change')->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').$state)
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger'),
                TextColumn::make('qty_after')->label('Qty after'),
                TextColumn::make('reason'),
                TextColumn::make('source_label')->label('Source')->state(function (StockMovement $record): string {
                    if ($record->source_type === null || $record->source_id === null) {
                        return '—';
                    }

                    return ($record->source instanceof Stocktake ? 'Stocktake' : class_basename($record->source ?? $record->source_type)).' #'.$record->source_id;
                }),
                TextColumn::make('note')->wrap(),
                IconColumn::make('is_override')->label('Override')->boolean(),
                TextColumn::make('user.name')->label('User'),
            ])->headerActions([])->recordActions([])->toolbarActions([]);
    }
}
