<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Domain\Money\Lkr;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GoodsReceiptsRelationManager extends RelationManager
{
    protected static string $relationship = 'goodsReceipts';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('number')->placeholder('Draft'),
            TextColumn::make('received_on')->date(), TextColumn::make('status')->badge(),
            TextColumn::make('total_cost_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
        ])->headerActions([])->recordActions([
            ViewAction::make()->url(fn (GoodsReceipt $record): string => GoodsReceiptResource::getUrl('view', ['record' => $record])),
        ])->toolbarActions([]);
    }
}
