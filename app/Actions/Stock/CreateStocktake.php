<?php

namespace App\Actions\Stock;

use App\Enums\StocktakeStatus;
use App\Models\Location;
use App\Models\Stocktake;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreateStocktake
{
    public function handle(User $actor, ?string $note): Stocktake
    {
        Gate::forUser($actor)->authorize('create', Stocktake::class);
        $note = trim($note ?? '');
        $note = $note === '' ? null : $note;
        if (mb_strlen($note ?? '') > 1000) {
            throw ValidationException::withMessages(['note' => 'The note must not exceed 1000 characters.']);
        }

        return Stocktake::query()->create([
            'location_id' => Location::default()->id, 'status' => StocktakeStatus::Draft,
            'created_by_id' => $actor->id, 'note' => $note,
        ]);
    }
}
