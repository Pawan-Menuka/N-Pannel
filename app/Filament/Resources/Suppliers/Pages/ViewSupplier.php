<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\SupplierDeleteAction;
use App\Models\Supplier;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** @extends ViewRecord<Supplier> */
class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), SupplierDeleteAction::make()];
    }
}
