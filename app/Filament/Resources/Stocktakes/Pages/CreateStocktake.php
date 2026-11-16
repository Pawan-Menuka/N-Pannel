<?php

namespace App\Filament\Resources\Stocktakes\Pages;

use App\Actions\Stock\CreateStocktake as CreateStocktakeAction;
use App\Filament\Resources\Stocktakes\StocktakeResource;
use App\Models\Stocktake;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** @extends CreateRecord<Stocktake> */
class CreateStocktake extends CreateRecord
{
    protected static string $resource = StocktakeResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();

        return app(CreateStocktakeAction::class)->handle($actor, $data['note'] ?? null);
    }
}
