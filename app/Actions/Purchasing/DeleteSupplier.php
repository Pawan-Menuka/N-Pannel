<?php

namespace App\Actions\Purchasing;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteSupplier
{
    public function handle(Supplier $supplier, User $actor): bool
    {
        return DB::transaction(function () use ($supplier, $actor): bool {
            $locked = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            Gate::forUser($actor)->authorize('delete', $locked);
            if ($locked->goodsReceipts()->exists()) {
                throw ValidationException::withMessages(['supplier' => 'This supplier has goods receipts. Deactivate the supplier instead.']);
            }

            return (bool) $locked->delete();
        });
    }
}
