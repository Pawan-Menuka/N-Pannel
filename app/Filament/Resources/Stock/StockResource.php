<?php

namespace App\Filament\Resources\Stock;

use App\Domain\Money\Lkr;
use App\Domain\Stock\StockLevelStatus;
use App\Filament\Resources\Stock\Pages\ListStock;
use App\Filament\Resources\Stock\Pages\ViewStock;
use App\Filament\Resources\Stock\RelationManagers\MovementsRelationManager;
use App\Filament\Support\AdjustStockAction;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\Variant;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/** @extends resource<Variant> */
class StockResource extends Resource
{
    protected static ?string $model = Variant::class;

    protected static ?string $slug = 'stock';

    protected static ?string $navigationLabel = 'Stock';

    protected static ?string $modelLabel = 'stock';

    protected static ?string $pluralModelLabel = 'Stock';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $recordTitleAttribute = 'sku';

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can('viewAny', StockLevel::class) ?? false;
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return Gate::inspect('viewAny', StockLevel::class);
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return Gate::inspect('view', new StockLevel);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function quantityExpression(): string
    {
        return 'coalesce((select qty from stock_levels where stock_levels.variant_id = variants.id and location_id = '.(int) Location::default()->id.'), 0)';
    }

    /** @return Builder<Variant> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('track_stock', true)->with('product')->select('variants.*')
            ->selectRaw(static::quantityExpression().' as qty')
            ->selectRaw('(select max(created_at) from stock_movements where stock_movements.variant_id = variants.id) as last_movement_at');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! Location::query()->where('is_default', true)->exists()) {
            return null;
        }

        $count = Variant::query()->where('track_stock', true)->where('is_active', true)
            ->whereNotNull('reorder_level')->whereRaw(static::quantityExpression().' <= reorder_level')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('product.title')->label('Product')->state(fn (Variant $record): string => $record->product->getTranslation('title', 'en')),
            TextColumn::make('sku')->label('SKU')->searchable(query: fn (Builder $query, string $search): Builder => $query
                ->where(fn (Builder $query): Builder => $query->where('sku', 'ilike', '%'.$search.'%')
                    ->orWhereHas('product', fn (Builder $query): Builder => $query->where('title->en', 'ilike', '%'.$search.'%')))),
            TextColumn::make('label'),
            TextColumn::make('qty')->label('In stock')->sortable()->badge()->color(fn (Variant $record): string => StockLevelStatus::isOut((int) $record->getAttribute('qty')) ? 'danger'
                    : (StockLevelStatus::isLow((int) $record->getAttribute('qty'), $record->reorder_level) ? 'warning' : 'success')),
            TextColumn::make('reorder_level')->label('Reorder level'),
            TextColumn::make('last_movement_at')->label('Last movement')->since(),
        ])->defaultSort(fn (Builder $query): Builder => $query
            ->orderByRaw("(select title->>'en' from products where products.id = variants.product_id)")->orderBy('sku'))
            ->filters([
                Filter::make('low_stock')->label('Low stock')->query(fn (Builder $query): Builder => $query
                    ->whereNotNull('reorder_level')->whereRaw(static::quantityExpression().' <= reorder_level')),
                Filter::make('out_of_stock')->label('Out of stock')->query(fn (Builder $query): Builder => $query->whereRaw(static::quantityExpression().' <= 0')),
                TernaryFilter::make('is_active')->label('Active'),
            ])->recordActions([
                AdjustStockAction::make(),
                Action::make('history')->label('History')->url(fn (Variant $record): string => static::getUrl('view', ['record' => $record])),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('product.title')->label('Product')->state(fn (Variant $record): string => $record->product->getTranslation('title', 'en')),
            TextEntry::make('sku')->label('SKU'),
            TextEntry::make('label'),
            TextEntry::make('qty')->label('In stock')->state(fn (Variant $record): int => (int) $record->stockLevels()
                ->where('location_id', Location::default()->id)->value('qty')),
            TextEntry::make('reorder_level'),
            TextEntry::make('last_cost_cents')->label('Last cost')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
        ]);
    }

    public static function getRelations(): array
    {
        return [MovementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListStock::route('/'), 'view' => ViewStock::route('/{record}')];
    }
}
