<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\HasOrderActions;
use App\Filament\Support\HasQuoteActions;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/** @extends ViewRecord<Order> */
class ViewOrder extends ViewRecord
{
    use HasOrderActions;
    use HasQuoteActions;

    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editQuote')->label('Edit quote')
                ->visible(fn (): bool => $this->quoteRecord()->status === OrderStatus::Quoted && $this->quoteActor()->can('update', $this->quoteRecord()))
                ->url(fn (): string => OrderResource::getUrl('quote', ['record' => $this->getRecord()])),
            ...$this->quoteActions(),
            ...$this->orderActions(),
        ];
    }

    protected function quoteRecord(): Order
    {
        return $this->getRecord();
    }

    protected function fulfilmentRecord(): Order
    {
        return $this->getRecord();
    }
}
