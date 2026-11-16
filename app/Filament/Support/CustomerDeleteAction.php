<?php

namespace App\Filament\Support;

use App\Actions\Customers\DeleteCustomer;
use App\Models\Customer;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CustomerDeleteAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()->visible(function (Customer $record): bool {
            /** @var User $actor */
            $actor = Filament::auth()->user();

            return Gate::forUser($actor)->allows('delete', $record);
        })->using(function (Customer $record): bool {
            /** @var User $actor */
            $actor = Filament::auth()->user();
            try {
                $deleted = app(DeleteCustomer::class)->handle($record, $actor);
                if ($deleted) {
                    $record->exists = false;
                }

                return $deleted;
            } catch (ValidationException $exception) {
                PurchasingValidation::notify($exception);

                return false;
            }
        })->failureNotification(null);
    }
}
