<?php

namespace App\Actions\Stock;

use App\Models\Stocktake;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateStocktakeNote
{
    public function __construct(private LockDraftStocktake $lockDraft) {}

    public function handle(Stocktake $stocktake, ?string $note, User $actor): Stocktake
    {
        $note = trim($note ?? '');
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($stocktake, $note, $actor): Stocktake {
            $locked = $this->lockDraft->handle($stocktake, $actor);
            if (mb_strlen($note ?? '') > 1000) {
                throw ValidationException::withMessages(['note' => 'The note must not exceed 1000 characters.']);
            }
            $locked->update(['note' => $note]);

            return $locked->refresh();
        });
    }
}
