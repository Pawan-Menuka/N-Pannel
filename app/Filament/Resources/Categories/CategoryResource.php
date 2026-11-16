<?php

namespace App\Filament\Resources\Categories;

use App\Actions\Catalog\CategoryHierarchy;
use App\Domain\Catalog\CatalogFormat;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Support\CatalogDeleteAction;
use App\Filament\Support\TranslatableFields;
use App\Models\Category;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/** @extends resource<Category> */
class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TranslatableFields::make('name', 'Name'),
            TranslatableFields::make('description', 'Description', textarea: true, requiredEnglish: false),
            TextInput::make('slug')->required()->maxLength(255)->rule('regex:'.CatalogFormat::SlugPattern)->unique(ignoreRecord: true),
            Select::make('parent_id')->label('Parent')->searchable()
                ->options(fn (?Category $record): array => Category::query()->whereNotIn('id', app(CategoryHierarchy::class)->excludedIds($record))
                    ->get()->mapWithKeys(fn (Category $category): array => [$category->id => $category->getTranslation('name', 'en')])->all())
                ->rules([fn (?Category $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    try {
                        app(CategoryHierarchy::class)->ensureParent($record, $value === null || $value === '' ? null : (int) $value);
                    } catch (ValidationException $exception) {
                        $fail($exception->validator->errors()->first());
                    }
                }]),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('parent'))
            ->columns([
                TextColumn::make('name')->state(fn (Category $record): string => $record->getTranslation('name', 'en'))
                    ->searchable(['name->en']),
                TextColumn::make('parent.name')->label('Parent')->state(fn (Category $record): ?string => $record->parent?->getTranslation('name', 'en')),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('sort_order')->label('Sort')->sortable(),
                TextColumn::make('products_count')->counts('products')->label('Products'),
            ])->filters([TernaryFilter::make('is_active')->label('Active')])
            ->defaultSort('sort_order')->reorderable('sort_order')->authorizeReorder(fn (): bool => static::canCreate())
            ->recordActions([EditAction::make(), CatalogDeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
