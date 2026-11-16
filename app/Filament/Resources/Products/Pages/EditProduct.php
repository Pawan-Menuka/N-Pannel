<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\CatalogDeleteAction;
use App\Filament\Support\TranslatableFields;
use App\Models\Product;
use Filament\Resources\Pages\EditRecord;

/** @extends EditRecord<Product> */
class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return TranslatableFields::fill($this->getRecord(), $data);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return TranslatableFields::merge($this->getRecord(), $data);
    }

    protected function getHeaderActions(): array
    {
        return [CatalogDeleteAction::make()];
    }
}
