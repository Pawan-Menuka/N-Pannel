<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Support\CatalogDeleteAction;
use App\Filament\Support\TranslatableFields;
use App\Models\Category;
use Filament\Resources\Pages\EditRecord;

/** @extends EditRecord<Category> */
class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

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
