<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Domain\Money\Lkr;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\QuoteSnapshot;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class QuoteSnapshotsRelationManager extends RelationManager
{
    protected static string $relationship = 'quoteSnapshots';

    protected static ?string $title = 'Revisions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewOrder::class && $ownerRecord instanceof Order && $ownerRecord->quote_revision >= 1 && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('revision', 'desc')->columns([
            TextColumn::make('revision'),
            TextColumn::make('created_at')->label('Sent at')->dateTime(),
            TextColumn::make('total_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            TextColumn::make('valid_until')->label('Valid until')->date(),
            TextColumn::make('required_advance_cents')->label('Advance')->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Lkr::formatWithCurrency($state)),
            TextColumn::make('createdBy.name')->label('Sent by'),
        ])->headerActions([])->recordActions([
            Action::make('print')->label('Print')->url(fn (QuoteSnapshot $record): string => route('filament.admin.quotes.print', ['order' => $record->order_id, 'revision' => $record->revision]))->openUrlInNewTab(),
        ])->toolbarActions([]);
    }
}
