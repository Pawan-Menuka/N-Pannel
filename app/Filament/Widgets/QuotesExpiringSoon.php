<?php

namespace App\Filament\Widgets;

use App\Actions\Reports\DashboardData;
use App\Domain\Money\Lkr;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Widgets\Concerns\DashboardAccess;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class QuotesExpiringSoon extends TableWidget
{
    use DashboardAccess;

    protected static ?int $sort = 5;

    public function table(Table $table): Table
    {
        return $table->heading('Quotes expiring soon')
            ->records(fn (): array => collect(app(DashboardData::class)->quotesExpiringSoon())->keyBy('id')->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('reference'),
                TextColumn::make('customer'),
                TextColumn::make('total_cents')->label('Total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextColumn::make('valid_until')->label('Valid until')->dateTime(),
            ])
            ->recordUrl(fn (array $record): string => OrderResource::getUrl('view', ['record' => $record['id']]));
    }
}
