<?php

namespace App\Filament\Support;

use App\Actions\Catalog\DeleteCategory;
use App\Actions\Catalog\DeleteProduct;
use App\Actions\Catalog\DeleteVariant;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

final class CatalogDeleteAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()->using(function (Category|Product|Variant $record): bool {
            /** @var User $actor */
            $actor = Filament::auth()->user();
            try {
                $deleted = match (true) {
                    $record instanceof Category => app(DeleteCategory::class)->handle($record, $actor),
                    $record instanceof Product => app(DeleteProduct::class)->handle($record, $actor),
                    default => app(DeleteVariant::class)->handle($record, $actor),
                };
                if ($deleted) {
                    $record->exists = false;
                }

                return $deleted;
            } catch (ValidationException $exception) {
                Notification::make()->danger()->title($exception->validator->errors()->first())->send();

                return false;
            }
        })->failureNotification(null);
    }
}
