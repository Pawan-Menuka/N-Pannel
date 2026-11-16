<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Domain\Money\Lkr;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('created_at')->label('Date')->dateTime(),
            TextColumn::make('order.reference')->label('Order'), TextColumn::make('kind')->badge(),
            TextColumn::make('method')->badge(), TextColumn::make('status')->badge(),
            TextColumn::make('amount_cents')->label('Amount')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('receipt_number'),
        ])->headerActions([])->recordActions([])->toolbarActions([]);
    }
}
