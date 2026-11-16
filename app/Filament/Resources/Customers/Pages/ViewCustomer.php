<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\CustomerDeleteAction;
use App\Models\Customer;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** @extends ViewRecord<Customer> */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), CustomerDeleteAction::make()];
    }
}
