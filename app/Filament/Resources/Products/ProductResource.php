<?php

namespace App\Filament\Resources\Products;

use App\Domain\Catalog\CatalogFormat;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Filament\Support\CatalogDeleteAction;
use App\Filament\Support\TranslatableFields;
use App\Models\Category;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** @extends resource<Product> */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('category_id')->label('Category')->required()->searchable()
                ->options(fn (?Product $record): array => Category::query()->where(fn (Builder $query): Builder => $query
                    ->where('is_active', true)->when($record, fn (Builder $query): Builder => $query->orWhereKey($record->category_id)))
                    ->get()->mapWithKeys(fn (Category $category): array => [$category->id => $category->getTranslation('name', 'en')])->all()),
            TranslatableFields::make('title', 'Title'),
            TranslatableFields::make('summary', 'Summary', textarea: true, requiredEnglish: false),
            TranslatableFields::make('description', 'Description', textarea: true, requiredEnglish: false),
            TextInput::make('brand')->maxLength(255),
            TextInput::make('slug')->required()->maxLength(255)->rule('regex:'.CatalogFormat::SlugPattern)->unique(ignoreRecord: true),
            Toggle::make('is_active')->label('Active')->default(true),
            TextInput::make('sort_order')->label('Sort')->integer()->required()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with('category'))
            ->columns([
                TextColumn::make('title')->state(fn (Product $record): string => $record->getTranslation('title', 'en'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->where('title->en', 'ilike', '%'.$search.'%')->orWhere('brand', 'ilike', '%'.$search.'%')
                        ->orWhereHas('variants', fn (Builder $query): Builder => $query->where('sku', 'ilike', '%'.$search.'%')))),
                TextColumn::make('category.name')->label('Category')->state(fn (Product $record): string => $record->category->getTranslation('name', 'en')),
                TextColumn::make('brand'),
                TextColumn::make('variants_count')->counts('variants')->label('Variants'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])->filters([
                SelectFilter::make('category_id')->label('Category')->options(fn (): array => Category::query()->get()
                    ->mapWithKeys(fn (Category $category): array => [$category->id => $category->getTranslation('name', 'en')])->all()),
                TernaryFilter::make('is_active')->label('Active'),
            ])->recordActions([ViewAction::make(), EditAction::make(), CatalogDeleteAction::make()]);
    }

    public static function getRelations(): array
    {
        return [VariantsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
