<?php

namespace App\Filament\Resources\Stocktakes\Pages;

use App\Filament\Resources\Stocktakes\StocktakeResource;
use App\Models\Stocktake;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** @extends ViewRecord<Stocktake> */
class ViewStocktake extends ViewRecord
{
    protected static string $resource = StocktakeResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
