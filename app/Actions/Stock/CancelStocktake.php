<?php

namespace App\Actions\Stock;

use App\Enums\StocktakeStatus;
use App\Models\Stocktake;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CancelStocktake
{
    public function __construct(private LockDraftStocktake $lockDraft) {}

    public function handle(Stocktake $stocktake, User $actor): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $actor): Stocktake {
            $locked = $this->lockDraft->handle($stocktake, $actor);
            $locked->update(['status' => StocktakeStatus::Cancelled]);

            return $locked->refresh();
        });
    }
}
