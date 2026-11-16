<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Domain\Customers\Balance;
use App\Domain\Money\Lkr;
use App\Models\Order;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('reference'), TextColumn::make('status')->badge(),
            TextColumn::make('total_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('paid_cents')->label('Paid')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('balance')->state(fn (Order $record): int => Balance::owed($record->total_cents, $record->paid_cents))->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('created_at')->label('Date')->dateTime(),
        ])->headerActions([])->recordActions([])->toolbarActions([]);
    }
}
