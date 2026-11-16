<?php

namespace App\Actions\Catalog;

use App\Models\StocktakeLine;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteVariant
{
    public function handle(Variant $variant, User $actor): bool
    {
        Gate::forUser($actor)->authorize('delete', $variant);

        return DB::transaction(function () use ($variant): bool {
            $variant = Variant::query()->lockForUpdate()->findOrFail($variant->getKey());
            $this->ensureNoHistory($variant);

            return (bool) $variant->delete();
        });
    }

    public function ensureNoHistory(Variant $variant): void
    {
        if ($variant->stockMovements()->exists() || $variant->orderLines()->exists() || $variant->goodsReceiptLines()->exists()
            || StocktakeLine::query()->where('variant_id', $variant->getKey())->exists()) {
            throw ValidationException::withMessages(['variant' => 'This item has history. Deactivate it instead.']);
        }
    }
}
