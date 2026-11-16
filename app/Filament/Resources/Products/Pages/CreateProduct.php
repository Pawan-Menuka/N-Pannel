<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;

/** @extends CreateRecord<Product> */
class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;
}
