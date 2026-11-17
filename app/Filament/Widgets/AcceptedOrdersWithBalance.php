<?php

namespace App\Filament\Widgets;

use App\Actions\Reports\DashboardData;
use App\Domain\Money\Lkr;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Widgets\Concerns\DashboardAccess;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class AcceptedOrdersWithBalance extends TableWidget
{
    use DashboardAccess;

    protected static ?int $sort = 4;

    public function table(Table $table): Table
    {
        return $table->heading('Accepted orders with a balance')
            ->records(fn (): array => collect(app(DashboardData::class)->acceptedWithBalance())->keyBy('id')->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('reference'),
                TextColumn::make('customer'),
                TextColumn::make('balance_cents')->label('Balance')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextColumn::make('confirmed_at')->label('Confirmed date')->dateTime(),
            ])
            ->recordUrl(fn (array $record): string => OrderResource::getUrl('view', ['record' => $record['id']]));
    }
}
