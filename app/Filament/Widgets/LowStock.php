<?php

namespace App\Filament\Widgets;

use App\Actions\Reports\DashboardData;
use App\Enums\UserRole;
use App\Filament\Resources\Stock\StockResource;
use App\Filament\Widgets\Concerns\DashboardAccess;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LowStock extends TableWidget
{
    use DashboardAccess;

    protected static ?int $sort = 6;

    /** @return list<UserRole> */
    protected static function allowedRoles(): array
    {
        return [UserRole::Admin, UserRole::Sales, UserRole::Stock];
    }

    public function table(Table $table): Table
    {
        return $table->heading('Low stock')
            ->headerActions([
                Action::make('viewAll')->label('View all')
                    ->url(StockResource::getUrl('index', ['filters' => ['low_stock' => ['isActive' => true]]])),
            ])
            ->records(fn (): array => collect(app(DashboardData::class)->lowStock())->keyBy('variant_id')->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('sku')->label('SKU'),
                TextColumn::make('description')->label('Name'),
                TextColumn::make('qty')->label('Qty'),
                TextColumn::make('reorder_level')->label('Reorder level'),
            ])
            ->recordUrl(fn (array $record): string => StockResource::getUrl('view', ['record' => $record['variant_id']]));
    }
}
