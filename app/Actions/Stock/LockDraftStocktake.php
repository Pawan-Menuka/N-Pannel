<?php

namespace App\Actions\Stock;

use App\Enums\StocktakeStatus;
use App\Models\Stocktake;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class LockDraftStocktake
{
    public function handle(Stocktake $stocktake, User $actor): Stocktake
    {
        $locked = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
        if ($locked->status !== StocktakeStatus::Draft) {
            throw ValidationException::withMessages(['stocktake' => 'Only draft stocktakes can be changed.']);
        }
        Gate::forUser($actor)->authorize('update', $locked);

        return $locked;
    }
}
