<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Actions\Purchasing\SaveSupplier;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\PurchasingValidation;
use App\Filament\Support\SupplierDeleteAction;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** @extends EditRecord<Supplier> */
class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();
        try {
            return app(SaveSupplier::class)->handle($this->getRecord(), [
                'name' => (string) $data['name'], 'phone_e164' => $data['phone_e164'] ?? null,
                'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null,
                'notes' => $data['notes'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? true),
            ], $actor);
        } catch (ValidationException $exception) {
            throw PurchasingValidation::forForm($exception);
        }
    }

    protected function getHeaderActions(): array
    {
        return [SupplierDeleteAction::make()];
    }
}
