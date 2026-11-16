<?php

namespace App\Filament\Resources\Stock\Pages;

use App\Filament\Resources\Stock\StockResource;
use App\Filament\Support\AdjustStockAction;
use App\Models\Variant;
use Filament\Resources\Pages\ViewRecord;

/** @extends ViewRecord<Variant> */
class ViewStock extends ViewRecord
{
    protected static string $resource = StockResource::class;

    protected function getHeaderActions(): array
    {
        return [AdjustStockAction::make()];
    }
}
