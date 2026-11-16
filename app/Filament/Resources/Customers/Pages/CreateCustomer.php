<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Customers\ResolveCustomer;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\PurchasingValidation;
use App\Models\Customer;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** @extends CreateRecord<Customer> */
class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected static bool $canCreateAnother = false;

    protected bool $resolvedExisting = false;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();
        try {
            $result = app(ResolveCustomer::class)->handle($data['phone_e164'] ?? null, (string) $data['name'], $actor, $data);
            $this->resolvedExisting = ! $result->created;

            return $result->customer;
        } catch (ValidationException $exception) {
            throw PurchasingValidation::forForm($exception);
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        if ($this->resolvedExisting) {
            $record = $this->getRecord();
            if (! $record instanceof Customer) {
                return null;
            }
            $name = $record->anonymised_at === null ? $record->name : 'Removed customer';

            return Notification::make()->info()->title('Already a customer: '.e($name));
        }

        return parent::getCreatedNotification();
    }

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
