<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Domain\Money\Lkr;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('issued_on', 'desc')->recordUrl(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record]))->columns([
            TextColumn::make('number')->url(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record])),
            TextColumn::make('issued_on')->date(),
            TextColumn::make('total_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('status')->badge(),
        ])->headerActions([])->recordActions([])->toolbarActions([]);
    }
}
