<?php

namespace App\Filament\Resources\GoodsReceipts\RelationManagers;

use App\Actions\Purchasing\DeleteGoodsReceiptLine;
use App\Actions\Purchasing\SaveGoodsReceiptLine;
use App\Domain\Money\Lkr;
use App\Enums\GoodsReceiptStatus;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\PurchasingValidation;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\User;
use App\Models\Variant;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public function isReadOnly(): bool
    {
        return parent::isReadOnly() || $this->receipt()->fresh()?->status !== GoodsReceiptStatus::Draft;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('variant_id')->label('Item')->required()->searchable()->live()
                ->getSearchResultsUsing(fn (string $search): array => Variant::query()->with('product')->where('is_active', true)->where('track_stock', true)
                    ->where(fn (Builder $query): Builder => $query->where('sku', 'ilike', '%'.$search.'%')
                        ->orWhereHas('product', fn (Builder $query): Builder => $query->where('title->en', 'ilike', '%'.$search.'%')))
                    ->orderBy('sku')->limit(50)->get()->mapWithKeys(fn (Variant $variant): array => [$variant->id => $this->variantLabel($variant)])->all())
                ->getOptionLabelUsing(function (mixed $value): ?string {
                    $variant = Variant::query()->with('product')->find($value);

                    return $variant instanceof Variant ? $this->variantLabel($variant) : null;
                })->afterStateUpdated(function (mixed $state, Set $set): void {
                    $variant = Variant::query()->find($state);
                    if ($variant instanceof Variant && $variant->last_cost_cents !== null) {
                        $set('unit_cost_cents', Lkr::format($variant->last_cost_cents));
                    }
                }),
            TextInput::make('qty')->label('Qty')->integer()->required()->minValue(1)->maxValue(1000000),
            MoneyInput::make('unit_cost_cents')->label('Unit cost')->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with('variant.product'))->defaultSort('sort_order')
            ->description(fn (): string => 'Receipt total: '.Lkr::formatWithCurrency($this->receipt()->fresh()->total_cost_cents ?? 0))
            ->columns([
                TextColumn::make('variant.sku')->label('SKU'),
                TextColumn::make('product')->state(fn (GoodsReceiptLine $record): string => $record->variant->product->getTranslation('title', 'en')),
                TextColumn::make('qty'),
                TextColumn::make('unit_cost_cents')->label('Unit cost')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
                TextColumn::make('line_total_cents')->label('Line total')->formatStateUsing(fn (int $state): string => Lkr::formatWithCurrency($state)),
            ])->headerActions([
                CreateAction::make()->authorize(fn (): bool => $this->canCreate())->using(fn (array $data): GoodsReceiptLine => $this->saveLine($data)),
            ])->recordActions([
                EditAction::make()->authorize(fn (GoodsReceiptLine $record): bool => $this->canEdit($record))
                    ->using(fn (array $data, GoodsReceiptLine $record): GoodsReceiptLine => $this->saveLine($data, $record)),
                DeleteAction::make()->authorize(fn (GoodsReceiptLine $record): bool => $this->canDelete($record))
                    ->using(function (GoodsReceiptLine $record): bool {
                        try {
                            return app(DeleteGoodsReceiptLine::class)->handle($record, $this->actor());
                        } catch (ValidationException $exception) {
                            PurchasingValidation::notify($exception);

                            return false;
                        }
                    }),
            ]);
    }

    protected function canCreate(): bool
    {
        return ! $this->isReadOnly() && $this->canUpdateOwner();
    }

    protected function canEdit(Model $record): bool
    {
        return ! $this->isReadOnly() && $this->canUpdateOwner() && parent::canEdit($record);
    }

    protected function canDelete(Model $record): bool
    {
        return ! $this->isReadOnly() && $this->canUpdateOwner() && parent::canDelete($record);
    }

    /** @param array<string, mixed> $data */
    private function saveLine(array $data, ?GoodsReceiptLine $record = null): GoodsReceiptLine
    {
        try {
            return app(SaveGoodsReceiptLine::class)->handle($this->receipt(), $record, (int) $data['variant_id'], (int) $data['qty'], (int) $data['unit_cost_cents'], $this->actor());
        } catch (ValidationException $exception) {
            throw PurchasingValidation::forForm($exception, $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data');
        }
    }

    private function canUpdateOwner(): bool
    {
        return Filament::auth()->user()?->can('update', $this->receipt()->fresh()) ?? false;
    }

    private function receipt(): GoodsReceipt
    {
        /** @var GoodsReceipt $receipt */
        $receipt = $this->getOwnerRecord();

        return $receipt;
    }

    private function variantLabel(Variant $variant): string
    {
        return $variant->sku.' — '.$variant->product->getTranslation('title', 'en').' — '.($variant->label ?? '');
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();

        return $actor;
    }
}
