<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected static ?string $title = 'Quotes and orders';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newSale')->label('New sale')->url(OrderResource::getUrl('new-sale'))
                ->visible(fn (): bool => OrderResource::canCreate()),
        ];
    }
}
