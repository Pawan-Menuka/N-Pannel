<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Filament\Resources\Pages\CreateRecord;

/** @extends CreateRecord<Category> */
class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;
}
