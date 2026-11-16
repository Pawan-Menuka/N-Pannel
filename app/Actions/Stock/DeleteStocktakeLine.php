<?php

namespace App\Actions\Stock;

use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteStocktakeLine
{
    public function __construct(private LockDraftStocktake $lockDraft) {}

    public function handle(StocktakeLine $line, User $actor): bool
    {
        return DB::transaction(function () use ($line, $actor): bool {
            $stocktake = Stocktake::query()->findOrFail($line->stocktake_id);
            $locked = $this->lockDraft->handle($stocktake, $actor);
            $freshLine = $locked->lines()->findOrFail($line->id);
            Gate::forUser($actor)->authorize('delete', $freshLine);

            return (bool) $freshLine->delete();
        });
    }
}
