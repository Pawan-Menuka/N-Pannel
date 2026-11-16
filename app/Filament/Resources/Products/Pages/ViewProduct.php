<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\TranslatableFields;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** @extends ViewRecord<Product> */
class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return TranslatableFields::fill($this->getRecord(), $data);
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
