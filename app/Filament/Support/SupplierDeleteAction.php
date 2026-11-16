<?php

namespace App\Filament\Support;

use App\Actions\Purchasing\DeleteSupplier;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

final class SupplierDeleteAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()->using(function (Supplier $record): bool {
            /** @var User $actor */
            $actor = Filament::auth()->user();
            try {
                $deleted = app(DeleteSupplier::class)->handle($record, $actor);
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
