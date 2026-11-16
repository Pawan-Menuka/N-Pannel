<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Customers\UpdateCustomer;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\CustomerDeleteAction;
use App\Filament\Support\PurchasingValidation;
use App\Models\Customer;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** @extends EditRecord<Customer> */
class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();
        try {
            return app(UpdateCustomer::class)->handle($this->getRecord(), $data, $actor);
        } catch (ValidationException $exception) {
            throw PurchasingValidation::forForm($exception);
        }
    }

    protected function getHeaderActions(): array
    {
        return [CustomerDeleteAction::make()];
    }
}
