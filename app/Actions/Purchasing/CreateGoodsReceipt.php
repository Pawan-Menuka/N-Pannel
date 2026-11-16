<?php

namespace App\Actions\Purchasing;

use App\Enums\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreateGoodsReceipt
{
    public function __construct(private ReceiptDetails $details) {}

    public function handle(User $actor, int $supplierId, string $receivedOn, ?int $locationId = null, ?string $supplierReference = null, ?string $note = null): GoodsReceipt
    {
        Gate::forUser($actor)->authorize('create', GoodsReceipt::class);

        return DB::transaction(function () use ($actor, $supplierId, $receivedOn, $locationId, $supplierReference, $note): GoodsReceipt {
            $data = $this->details->validate($supplierId, $receivedOn, $supplierReference, $note);
            $location = $locationId === null ? Location::default() : Location::query()->find($locationId);
            if ($location === null || ! $location->is_active) {
                throw ValidationException::withMessages(['location_id' => 'Choose an active location.']);
            }

            return GoodsReceipt::query()->create($data + [
                'location_id' => $location->id, 'status' => GoodsReceiptStatus::Draft, 'created_by_id' => $actor->id,
            ]);
        });
    }
}
